{{#if description}}<p>{{{description}}}</p>{{/if}}
{{#if name}}<p>{{name}}</p>{{/if}}
{{#if header}}<p>{{header}}</p>{{/if}}

{{#if rowList}}
    {{#tableTag width="100%" border=1 cellpadding=5}}
        {{#if columnList}}
            {{#trTag}}
                {{#each columnList}}
                    {{#tdTag align=attrs.align}}
                        <strong>{{label}}</strong>
                    {{/tdTag}}
                {{/each}}
            {{/trTag}}
        {{/if}}

        {{#each rowList}}
            {{#trTag}}
                {{#each .}}
                    {{#tdTag align=attrs.align}}
                        {{#if isBold}}<strong>{{/if}}
                        {{value}}
                        {{#if isBold}}</strong>{{/if}}
                    {{/tdTag}}
                {{/each}}
            {{/trTag}}
        {{/each}}
    {{/tableTag}}
{{else}}
    <p>{{noDataLabel}}</p>
{{/if}}
