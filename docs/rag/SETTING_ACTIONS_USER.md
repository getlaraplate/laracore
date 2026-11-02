---
module: core
audience: user
cross_cutting_user: true
---
# Setting actions and available choices: user guide

## The play icon in the Settings grid

Some settings show a play icon at the start of their row in Filament > Settings. It runs the command the
setting was shipped with. For example, the AI model settings use it to refresh the list of models the
providers offer.

- Hover the icon to see the command it runs.
- The icon appears only to users who may update settings.
- It runs at once, without asking for confirmation.
- A notification reports the outcome:
  - **Command completed**: the command finished; the notification shows the end of its output.
  - **Command failed**: the command ran but reported an error; the output says why.
  - **Action refused**: the command could not be run as declared (for example it is not installed), and
    nothing ran.
  - **Command queued**: the command runs in the background; its effect appears when the queue processes it.

Nobody can change the command from the panel: it belongs to the software, not to the setting's value. The
edit form shows it read-only.

## Values that are no longer available

Settings with a fixed list of choices show a drop-down. When a list is refreshed (as the AI model lists
are), the value you saved may no longer be in it, for example because the provider withdrew a model.

- In the grid, that value is shown in a warning colour with a warning icon; hovering it explains why.
- In the edit form, the value is still selectable, labelled "(no longer available)", with a note under
  the field.
- Nothing changes the value for you: it stays saved until you choose another one.

## Read-only values and confirmations

Some settings are written by the application itself, for example the embedding model that currently serves
search (`features.embeddings.serving_model`), which the embedding model switch sets. Their value is shown greyed out in the edit form and cannot be
changed there.

Changing a few settings has a cost, and the form asks first: a window explains what will happen and you
confirm or cancel. Cancel changes nothing. A setting can also be locked while an operation runs; the
reason is written under the field. For example, choosing another embedding model in
`features.embeddings.model` shows how many records will be re-embedded and that search uses keywords only
until the switch ends, and the field stays locked until it ends.
