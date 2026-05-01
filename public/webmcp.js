/*
 * WebMCP integration for the MailBaby Mail API.
 *
 * Registers every operation in the OpenAPI spec as a browser-side MCP tool
 * via navigator.modelContext.registerTool(). When a user opens this page in a
 * WebMCP-aware agent (Chrome 146+ with `navigator.modelContext`), the agent
 * can invoke these tools directly without needing a separate MCP server
 * connection.
 *
 * Authentication: tools read the X-API-KEY from localStorage under the
 * "mailbaby_api_key" key. Set it once in your browser console:
 *
 *   localStorage.setItem('mailbaby_api_key', 'YOUR-KEY-FROM-INTERSERVER');
 *
 * The pingServer tool works without a key.
 */
(async function () {
    if (!('modelContext' in navigator) || typeof navigator.modelContext.registerTool !== 'function') {
        return; // Not running in a WebMCP-capable agent.
    }

    const API_KEY_STORAGE = 'mailbaby_api_key';

    function getApiKey() {
        try {
            return localStorage.getItem(API_KEY_STORAGE) || '';
        } catch (_) {
            return '';
        }
    }

    function buildUrl(path, query) {
        const url = new URL(path, location.origin);
        if (query) {
            for (const [k, v] of Object.entries(query)) {
                if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
            }
        }
        return url;
    }

    function asContent(text, isError) {
        return {
            content: [{ type: 'text', text: typeof text === 'string' ? text : JSON.stringify(text, null, 2) }],
            isError: !!isError,
        };
    }

    async function callApi(method, path, opts) {
        opts = opts || {};
        const url = buildUrl(path, opts.query);
        const headers = { Accept: 'application/json' };
        const key = getApiKey();
        if (key) headers['X-API-KEY'] = key;
        const init = { method, headers };
        if (opts.body !== undefined) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(opts.body);
        }
        let response;
        try {
            response = await fetch(url, init);
        } catch (e) {
            return asContent('Network error: ' + (e && e.message ? e.message : e), true);
        }
        const text = await response.text();
        let data;
        try { data = JSON.parse(text); } catch (_) { data = { raw: text }; }
        if (!response.ok && response.status === 401 && !key) {
            return asContent(
                'Unauthorized. Set your MailBaby API key with `localStorage.setItem("mailbaby_api_key", "...")` ' +
                'and reload this page. Get your key from https://my.interserver.net/account_security.',
                true,
            );
        }
        return asContent(data, !response.ok);
    }

    /**
     * Walks an OpenAPI 3 path object and returns a JSON Schema for the tool's
     * input parameters. Combines path/query parameters with the JSON request
     * body schema (if any) into a single flat object.
     */
    function buildInputSchema(spec, pathItem, op) {
        const schema = { type: 'object', properties: {}, required: [] };
        const params = (pathItem.parameters || []).concat(op.parameters || []);
        for (const raw of params) {
            const p = resolveRef(spec, raw);
            if (!p || !p.name) continue;
            if (p.in !== 'path' && p.in !== 'query') continue;
            const ps = simplifySchema(spec, p.schema || { type: 'string' });
            if (p.description) ps.description = p.description;
            if (p.example !== undefined) ps.example = p.example;
            schema.properties[p.name] = ps;
            if (p.required || p.in === 'path') schema.required.push(p.name);
        }
        if (op.requestBody && op.requestBody.content) {
            const content = op.requestBody.content;
            const json = content['application/json']
                || content['application/x-www-form-urlencoded']
                || content['multipart/form-data'];
            if (json && json.schema) {
                const body = simplifySchema(spec, json.schema);
                if (body.properties) {
                    for (const [k, v] of Object.entries(body.properties)) {
                        schema.properties[k] = v;
                    }
                }
                if (Array.isArray(body.required)) {
                    for (const r of body.required) schema.required.push(r);
                }
            }
        }
        if (!schema.required.length) delete schema.required;
        return schema;
    }

    function resolveRef(spec, item) {
        if (!item || typeof item !== 'object' || !item.$ref) return item;
        const ref = item.$ref;
        if (typeof ref !== 'string' || !ref.startsWith('#/')) return item;
        const parts = ref.slice(2).split('/');
        let cur = spec;
        for (const p of parts) {
            const k = p.replace(/~1/g, '/').replace(/~0/g, '~');
            if (cur == null || typeof cur !== 'object' || !(k in cur)) return item;
            cur = cur[k];
        }
        return resolveRef(spec, cur);
    }

    function simplifySchema(spec, schema) {
        schema = resolveRef(spec, schema || {});
        const out = {};
        for (const k of ['type', 'description', 'enum', 'format', 'minimum', 'maximum',
            'minLength', 'maxLength', 'pattern', 'default', 'example']) {
            if (k in schema) out[k] = schema[k];
        }
        if (schema.nullable) out.nullable = true;
        if (schema.type === 'object' && schema.properties) {
            out.properties = {};
            for (const [k, v] of Object.entries(schema.properties)) {
                out.properties[k] = simplifySchema(spec, v);
            }
            if (schema.required) out.required = schema.required;
        }
        if (schema.type === 'array' && schema.items) {
            out.items = simplifySchema(spec, schema.items);
        }
        return out;
    }

    function partitionArgs(arguments_, pathParams, queryParams, hasBody) {
        const path = {}, query = {}, body = {};
        for (const [k, v] of Object.entries(arguments_ || {})) {
            if (pathParams.has(k)) path[k] = v;
            else if (queryParams.has(k)) query[k] = v;
            else if (hasBody) body[k] = v;
        }
        return { path, query, body };
    }

    function fillPath(template, pathArgs) {
        return template.replace(/\{([^}]+)\}/g, (_, name) =>
            encodeURIComponent(pathArgs[name] != null ? String(pathArgs[name]) : ''),
        );
    }

    let spec;
    try {
        const r = await fetch('/spec/openapi.json', { headers: { Accept: 'application/json' } });
        if (!r.ok) return;
        spec = await r.json();
    } catch (_) {
        return;
    }
    if (!spec || !spec.paths) return;

    for (const [path, pathItem] of Object.entries(spec.paths)) {
        for (const method of ['get', 'post', 'put', 'patch', 'delete']) {
            const op = pathItem[method];
            if (!op || !op.operationId) continue;

            const pathParams = new Set();
            const queryParams = new Set();
            const params = (pathItem.parameters || []).concat(op.parameters || []);
            for (const raw of params) {
                const p = resolveRef(spec, raw);
                if (!p || !p.name) continue;
                if (p.in === 'path') pathParams.add(p.name);
                else if (p.in === 'query') queryParams.add(p.name);
            }
            const hasBody = !!op.requestBody;
            const inputSchema = buildInputSchema(spec, pathItem, op);

            const summary = (op.summary || '').trim();
            const description = (op.description || '').trim();
            let toolDesc = summary;
            if (description && description !== summary) {
                toolDesc += (toolDesc ? '\n\n' : '') + description;
            }
            if (!toolDesc) toolDesc = method.toUpperCase() + ' ' + path;
            // Cap descriptions at 900 chars (sentence-aware) — same budget as
            // the server-side parser uses for the MCP tool list, so the
            // WebMCP and MCP descriptions stay aligned.
            if (toolDesc.length > 900) {
                let cut = -1;
                for (const sep of ['. ', '? ', '! ', '\n\n']) {
                    const i = toolDesc.lastIndexOf(sep, 900);
                    if (i > cut) cut = i;
                }
                toolDesc = cut > 700 ? toolDesc.slice(0, cut + 1) : (toolDesc.slice(0, 897) + '...');
            }

            try {
                navigator.modelContext.registerTool({
                    name: op.operationId,
                    description: toolDesc,
                    inputSchema: inputSchema,
                    execute: async (input /*, client */) => {
                        const parts = partitionArgs(input, pathParams, queryParams, hasBody);
                        const finalPath = fillPath(path, parts.path);
                        const opts = {};
                        if (Object.keys(parts.query).length) opts.query = parts.query;
                        if (hasBody && Object.keys(parts.body).length) opts.body = parts.body;
                        return callApi(method.toUpperCase(), finalPath, opts);
                    },
                });
            } catch (e) {
                // Duplicate name or invalid schema — skip and continue.
                if (typeof console !== 'undefined' && console.warn) {
                    console.warn('[WebMCP] failed to register tool ' + op.operationId + ': ' + (e && e.message ? e.message : e));
                }
            }
        }
    }
})();
