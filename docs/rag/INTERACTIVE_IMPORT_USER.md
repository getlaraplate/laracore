---
module: core
audience: user
cross_cutting_user: true
locale: en
canonical_source: core/interactive-import
safe_source_label: "Core · Interactive bulk import"
version: '1.0'
policy_classification: user_safe
policy_classification_version: in-app-docs-v1
required_permissions:
  - core_import_sessions.insert
tenant_scope: global
---
# Interactive bulk import — user and operator guide

## What it does

Laraplate lets an operator import records into a chosen entity by uploading a file, mapping its columns to the entity's fields, checking a spreadsheet-like preview, and running the import in the background. It is available in the SPA (under `/app/crud/imports`) and monitored from the Filament backoffice. Supported file formats are CSV, XLSX, ODS and JSON.

Only entities the application explicitly registers as importable can be targeted; the import never writes to an arbitrary table.

## The steps

1. **Choose what to import.** Pick a registered entity (for example Users, Tags, Categories, Contents, Items, Parties, Tickets). Each entity declares the fields a column can fill and which of them are required.
2. **Upload a file.** The columns of the file are detected automatically, and the tool suggests a mapping by matching column headers to field names.
3. **Map the columns.** For each target field, choose the source column (or leave it unmapped). Required fields are marked; the import cannot start until every required field is mapped.
4. **Check the preview.** A sample of the first rows is shown exactly as they will be read.
5. **Run.** The import is queued and runs in the background. Progress and per-outcome counts (created, updated, skipped, failed) are shown, and can be refreshed.

Re-importing the same file is safe: each entity de-duplicates by a stable natural key (an email, a slug, a name, a code, a SKU), so a second run updates existing records instead of creating duplicates.

## Relation columns

Some fields link a record to other records — a content's tags, categories and contributors; an item's company. These are always expressed by a **human-readable natural key** (a name, slug or code), never an internal id. A relation column can hold several values separated by a delimiter (for example `sport, music`). The mapping screen flags relation columns and previews how the first sample row splits into individual values.

What happens to a value that matches no existing record depends on the field:

| Policy | Meaning | Example |
|--------|---------|---------|
| Created | The related record is created on the fly | Tags |
| Fails the row | The row is rejected with a per-field error | Categories, contributors (import them first) |
| Skipped | The unmatched value is silently ignored | (available, unused by default) |

## The failure report

The import never stops on a bad row: each row runs on its own, so one failure does not abort the rest. Failed rows are collected in a per-row report you can download as a CSV — each entry names the row number and the fields that failed and why.

## Notifications when it finishes

Because the import runs in the background, you are told when it ends through the in-app **notification bell** (top bar of every app, and the Filament backoffice). An import-finished notification shows whether it succeeded, was partial (some rows failed), or failed, with the counts, and can be clicked to open the related import. Notifications are scoped to the module you are in, and the unread badge updates automatically (checked about every 30 seconds). Opening the tray marks what you read; "Mark all read" clears the badge.
