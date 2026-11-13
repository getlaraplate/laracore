---
module: core
audience: user
cross_cutting_user: true
locale: en
canonical_source: core/search-matching
safe_source_label: "Core · Adaptive search matching"
version: '1.0'
policy_classification: user_safe
policy_classification_version: in-app-docs-v1
required_permissions: []
tenant_scope: global
---
# Adaptive search matching — user and API guide

## What adaptive matching does

Laraplate adjusts text matching to the shape of the query while preserving exact matches and identifiers. Short searches are conservative because they commonly represent names, acronyms, invoice numbers, SKUs, emails, UUIDs, or numeric identifiers. Longer natural-language searches may match a lower percentage of meaningful words to improve recall.

This behavior applies to orchestrated CRUD search on both the authenticated `/app` surface and the optional `/api/v1` surface. Existing requests remain valid.

## Default behavior

When `matching` is omitted or set to `auto`:

| Query shape | Behavior |
|-------------|----------|
| One acronym/code/number/email/UUID | Strict; no typo expansion |
| One ordinary word | Exact results first; prefix matching; at most one typo when long enough |
| Two meaningful words | Both words required; exact phrase preferred; limited typo expansion |
| Three or four keyword-like words | Conservative end of the automatic range |
| Five or more keyword-like words | 70% down to 65% as the query grows |
| Natural-language sentence | 65% down to 60%; up to two eligible typo corrections |

Italian and English stopwords are ignored only when deciding how many meaningful words the query contains. They remain part of the original engine query where appropriate.

Examples:

- `ACME`, `CRM`, `2026`, `INV-1042`, and `mario@example.it` remain strict.
- `Giusppe` may match `Giuseppe`, while exact `Giuseppe` ranks first.
- `Mario Rossi` requires both meaningful words.
- `fatture fornitori italiane scadute giugno` can match documents containing most, rather than necessarily all, meaningful words.

A mixed query containing a protected identifier, such as `ACME fatture`, is conservative: it requires complete token coverage and disables typo expansion for the complete keyword query by default. This guarantees that the identifier cannot be the term discarded by a percentage threshold and keeps behavior comparable across engines.

## Matching preferences

Use the optional `matching` query parameter:

```text
matching=auto
matching=strict
matching=balanced
matching=tolerant
```

- `auto` selects behavior from the query.
- `strict` requires every meaningful word and permits at most one one-character correction on one eligible word.
- `balanced` requires all words through three, then 75% at four, 65% at five-to-eight, and 60% at nine or more; at most two eligible words may be corrected.
- `tolerant` requires all words through two, two of three, three of four, 55% at five-to-eight, and 50% at nine or more; at most three eligible words may be corrected.

All percentages are applied conservatively as whole required terms. Exact and complete matches remain ahead of partial or fuzzy candidates. Set `matching_options[typo_tolerance]=false` when even the controlled strict correction is not wanted.

Preferences are adaptive hints. They do not bypass identifier protection.

### What `matching` does not change

`matching` tunes **text** matching only. It is applied to the keyword and hybrid retrieval passes
and never to the pure vector pass, because an embedding has no notion of typo tolerance or token
coverage.

It therefore does not change:

- how many retrieval strategies run (that is the plan: keyword only, or keyword + vector + hybrid);
- how their results are fused (weights, RRF constant, agreement bonus);
- whether results are reranked, or how the reranked score is blended.

The operator's retrieval tuning switch (`search.adaptive_tuning`) is the opposite: it can change
fusion and reranking per kind of query, and never the `matching` preference.

A query that returns nothing under `strict` and returns results under `tolerant` changed only the
lexical recall. If you need semantic recall (different words, same meaning), that comes from vector
retrieval being active, not from a looser preference.

## Required terms and exact phrases

The free-text `qs` parameter accepts familiar search operators:

```text
"Mario Rossi"      required exact phrase
+Mario +Rossi       both terms required, in any order and position
+Mario Rossi        only Mario is required
```

A quoted phrase is mandatory, adjacent, and ordered. `"Mario Rossi"` matches `incontro con Mario Rossi`, but not `Mario Antonio Rossi` or `Rossi Mario`. A plus-prefixed term is mandatory without imposing order or adjacency. `+"Mario Rossi"` is accepted and means the same as `"Mario Rossi"` because quoted phrases are already mandatory.

Required phrases and terms are case-insensitive under normal search normalization, but they are never fuzzy. They must be satisfied before `strict`, `balanced`, `tolerant`, or `auto` applies its coverage threshold to the remaining free terms.

Example:

```http
GET /app/core/search/customers?qs=%22Mario%20Rossi%22%20%2BMilano%20consulente%20software&mode=orchestrated&matching=balanced
```

This requires the phrase `Mario Rossi` and the term `Milano`; `balanced` applies only to `consulente software`. Use `\"` for a literal quote. An unmatched quote and a standalone `+` are treated as ordinary text rather than rejected.

## Granular controls

Advanced callers may provide:

```text
matching_options[typo_tolerance]=true|false
matching_options[max_edits]=0|1
matching_options[prefix]=true|false
matching_options[operator]=and|or
matching_options[minimum_should_match]=1..100
matching_options[identifier_typos]=true|false
```

Example:

```http
GET /app/core/search/customers?qs=Mario%20Rossi&mode=orchestrated&matching=balanced&matching_options[max_edits]=1
```

To explicitly permit typo expansion for identifiers:

```http
GET /app/core/search/invoices?qs=INV-1042&mode=orchestrated&matching=tolerant&matching_options[identifier_typos]=true
```

Use identifier typo expansion sparingly because it can return incorrect codes or records.
UUIDs remain exact even when identifier typo expansion is requested.

## Response metadata

Orchestrated search responses expose the effective decision under `meta.search.matching` or the enclosing advanced-search metadata, depending on the CRUD response wrapper:

```json
{
  "matching": {
    "requested_preference": "auto",
    "effective_preference": "balanced",
    "significant_token_count": 2,
    "token_kinds": ["word", "word"],
    "protected_token_count": 0,
    "eligible_token_count": 2,
    "fuzzy_token_limit": 1,
    "required_term_count": 0,
    "required_phrase_count": 0,
    "options": {
      "max_edits": 1,
      "operator": "and",
      "minimum_should_match": 100
    },
    "degraded": []
  }
}
```

`degraded` identifies requested semantics that the active search engine cannot represent exactly. Result scores are not numerically comparable across different engines even when the matching semantics are the same.

## Engine expectations

- Elasticsearch offers native typo, prefix, boolean, phrase-boost, and token-threshold controls.
- Typesense offers native typo and prefix controls plus exact-match priority; some numeric boost and boolean details may be degraded.
- PostgreSQL can provide typo similarity when `pg_trgm` is installed and enabled.
- MySQL, MariaDB, SQLite, and the current Oracle database fallback use case-insensitive prefix/substring matching and report unsupported typo semantics as degraded.
- Oracle Text `CONTEXT` integration is a separate schema-aware feature and is not enabled by the portable database fallback.
