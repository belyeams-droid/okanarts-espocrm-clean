<?php
/***********************************************************************************
 * The contents of this file are subject to the Extension License Agreement
 * ("Agreement") which can be viewed at
 * https://www.espocrm.com/extension-license-agreement/.
 * By copying, installing downloading, or using this file, You have unconditionally
 * agreed to the terms and conditions of the Agreement, and You may not use this
 * file except in compliance with the Agreement. Under the terms of the Agreement,
 * You shall not license, sublicense, sell, resell, rent, lease, lend, distribute,
 * redistribute, market, publish, commercialize, or otherwise transfer rights or
 * usage to the software or any modified version or derivative work of the software
 * created by or for you.
 *
 * Copyright (C) 2015-2025 EspoCRM, Inc.
 *
 * License ID: 33bfb348770a2d79a1348fb026046b7f
 ************************************************************************************/

namespace Espo\Modules\Advanced\Core\Workflow\Actions;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\Advanced\Tools\Workflow\Core\PlaceholderHelper;
use Espo\Core\Exceptions\Error;
use Espo\Modules\Advanced\Core\Workflow\Exceptions\SendRequestError;
use RuntimeException;
use stdClass;

use const CURLE_OPERATION_TIMEDOUT;
use const CURLE_OPERATION_TIMEOUTED;
use const CURLINFO_HEADER_SIZE;
use const CURLINFO_HTTP_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HEADER;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const JSON_UNESCAPED_UNICODE;

/**
 * @noinspection PhpUnused
 */
class SendRequest extends Base
{
    private ?PlaceholderHelper $placeholderHelper = null;

    /**
     * @throws Error
     * @throws SendRequestError
     */
    protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool
    {
        $requestType = $actionData->requestType ?? null;
        $contentType = $actionData->contentType ?? null;
        $requestUrl = $actionData->requestUrl ?? null;
        $content = $actionData->content ?? null;
        $contentVariable = $actionData->contentVariable ?? null;
        $additionalHeaders = $actionData->headers ?? [];

        if (!$requestUrl) {
            throw new Error("Empty request URL.");
        }

        if (!$requestType) {
            throw new Error("Empty request type.");
        }

        if (!in_array($requestType, ['POST', 'PUT', 'PATCH', 'DELETE', 'GET'])) {
            throw new Error("Not supported request type.");
        }

        /** @var non-empty-string $requestUrl */
        $requestUrl = $this->applyVariables($requestUrl);

        $contentTypeList = [
            null,
            'application/json',
            'application/x-www-form-urlencoded',
        ];

        if (!in_array($contentType, $contentTypeList)) {
            throw new Error("Unsupported content-type.");
        }

        $isGet = $requestType === 'GET';

        $payload = $this->getPayload(
            isJson: $contentType === 'application/json' && !$isGet,
            content: $content,
            contentVariable: $contentVariable,
            headers: $additionalHeaders,
        );

        $timeout = $this->config->get('workflowSendRequestTimeout', 7);

        $ch = curl_init();

        if ($ch === false) {
            throw new RuntimeException("CURL init error.");
        }

        if ($isGet && $payload) {
            $separator = (parse_url($requestUrl, PHP_URL_QUERY) === null) ? '?' : '&';

            $requestUrl .= $separator . $payload;
        }

        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requestType);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);

        if (!$isGet) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $headers = [];

        if ($contentType) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        foreach ($additionalHeaders as $header) {
            $header = $this->applyVariables($header);
            $header = $this->getPlaceholderHelper()->applySecrets($header);

            $headers[] = $header;
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $this->logSendRequest($isGet, $payload);

        $response = curl_exec($ch);

        if ($response === false || $response === true) {
            $response = '';
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        //$header = mb_substr($response, 0, $headerSize);
        $body = mb_substr($response, $headerSize);

        curl_close($ch);

        if (!is_int($code)) {
            $code = 0;
        }

        if ($code && $code >= 400 && $code <= 500) {
            $message = "Workflow: Send Request action: $requestType $requestUrl; Error $code response.";

            throw new SendRequestError($message, $code);
        }

        if ($error && in_array($error, [CURLE_OPERATION_TIMEDOUT, CURLE_OPERATION_TIMEOUTED])) {
            throw new Error("Workflow: Send Request action: $requestUrl; Timeout.");
        }

        if ($code < 200 || $code >= 300) {
            $message = "Workflow: Send Request action: $code response.";

            throw new SendRequestError($message, $code);
        }

        $this->setResponseVariables($body, $code);

        return true;
    }

    /**
     * @param string[] $headers
     * @throws Error
     */
    private function getPayload(
        bool $isJson,
        ?string $content,
        ?string $contentVariable,
        array $headers,
    ): ?string {

        if (!$isJson) {
            foreach ($headers as $header) {
                if (str_starts_with(strtolower($header), 'content-type: application/json')) {
                    $isJson = true;

                    break;
                }
            }
        }

        if (!$contentVariable) {
            if (!$content) {
                return null;
            }

            $content = $this->applyVariables($content, true);

            if ($isJson) {
                return $content;
            }

            $post = json_decode($content, true);

            foreach ($post as $k => $v) {
                if (is_array($v)) {
                    $post[$k] = '"' . implode(', ', $v) . '"';
                }
            }

            return http_build_query($post);
        }

        if ($contentVariable[0] === '$') {
            $contentVariable = substr($contentVariable, 1);

            if (!$contentVariable) {
                throw new Error("Empty variable.");
            }
        }

        $content = $this->getVariables()->$contentVariable ?? null;

        if (is_string($content)) {
            return $content;
        }

        if (!$content) {
            return null;
        }

        if (!$isJson) {
            if ($content instanceof stdClass) {
                return http_build_query($content);
            }

            throw new Error("Workflow: Send Request: Bad value in payload variable. Should be string or object.");
        }

        if (
            is_array($content) ||
            $content instanceof stdClass ||
            is_scalar($content)
        ) {
            $result = json_encode($content, JSON_UNESCAPED_UNICODE);

            if ($result === false) {
                throw new Error("Workflow: Send Request: Could not JSON encode payload.");
            }

            return $result;
        }

        throw new Error("Workflow: Send Request action: Bad value in payload variable.");
    }

    /**
     * @param string $body
     * @param int $code
     */
    private function setResponseVariables($body, $code): void
    {
        if (!$this->hasVariables()) {
            return;
        }

        $this->updateVariables(
            (object) [
                '_lastHttpResponseBody' => $body,
                '_lastHttpResponseCode' => $code,
            ]
        );

        //$this->variables->_lastHttpResponseBody = $body;
    }

    private function applyVariables(string $content, bool $isJson = false): string
    {
        $target = $this->getEntity();

        foreach ($target->getAttributeList() as $a) {
            $value = $target->get($a) ?? '';

            if (
                $isJson &&
                $target->getAttributeParam($a, 'isLinkMultipleIdList') &&
                $target->get($a) === null
            ) {
                $relation = $target->getAttributeParam($a, 'relation');

                if ($relation && $target->hasLinkMultipleField($relation)) {
                    $value = $target->getLinkMultipleIdList($relation);
                }
            }

            if (!$isJson && is_array($value)) {
                $arr = [];

                foreach ($value as $item) {
                    if (is_string($item)) {
                        $arr[] = str_replace(',', '_COMMA_', $item);
                    }
                }

                $value = implode(',', $arr);
            }

            if (is_string($value)) {
                $value = $isJson ?
                    $this->escapeStringForJson($value) :
                    str_replace(["\r\n", "\r", "\n"], "\\n", $value);
            } else if (is_numeric($value)) {
                $value = strval($value);
            } else if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            } else if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            if (is_string($value)) {
                $content = str_replace('{$' . $a . '}', $value, $content);
            }
        }

        $variables = $this->getVariables();

        foreach (get_object_vars($variables) as $key => $value) {
            if (
                !is_string($value) &&
                !is_int($value) &&
                !is_float($value) &&
                !is_array($value) &&
                !is_bool($value)
            ) {
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $value = strval($value);
            } else if (is_array($value)) {
                if (!$isJson) {
                    continue;
                }

                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            } else if (is_string($value)) {
                $value = $isJson ?
                    $this->escapeStringForJson($value) :
                    str_replace(["\r\n", "\r", "\n"], "\\n", $value);
            } else if (is_bool($value)) {  /** @phpstan-ignore-line function.alreadyNarrowedType */
                $value = $value ? 'true' : 'false';
            } else {
                continue;
            }

            /** @var string $value */

            $content = str_replace("{\$\$$key}", $value, $content);
        }

        return $content;
    }

    private function escapeStringForJson(string $string): string
    {
        $encoded = json_encode($string, JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            $encoded = '';
        }

        return substr($encoded, 1, -1);
    }

    private function getPlaceholderHelper(): PlaceholderHelper
    {
        $this->placeholderHelper ??= $this->injectableFactory->create(PlaceholderHelper::class);

        return $this->placeholderHelper;
    }

    private function logSendRequest(bool $isGet, ?string $post): void
    {
        $logMessage = "Workflow: Send request.";

        if (!$isGet) {
            $logMessage .= " Payload:" . $post;
        }

        $GLOBALS['log']->debug($logMessage);
    }
}
