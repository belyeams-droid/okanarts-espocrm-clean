if (!$deposit) {
    $deposit = $this->entityManager->getEntity('CShopifyTourDeposit');
}

// -----------------------------------------
// GET tourCode from Tour entity
// -----------------------------------------
$tourCode = null;

if ($tour) {
    $tourCode = $tour->get('tourCode');
}

// Normalize to underscore format
if ($tourCode) {
    $tourCode = str_replace('-', '_', trim($tourCode));
}

$deposit->set([
    'name' => 'DEPOSIT — ' . $title,
    'productTitle' => $title,
    'shopifySku' => $sku,
    'amount' => $price,
    'shopifyOrderId' => $orderId,
    'shopifyLineItemId' => $uniqueLineId,
    'orderDate' => $orderDate,
    'shopifyEmail' => $email,
    'contractStatus' => 'Deposit Received',
    'contactId' => $contactId,

    // 🔥 CRITICAL: keep BOTH in sync
    'tourId' => $tourId,
    'tourCode' => $tourCode
]);

$this->entityManager->saveEntity($deposit);
