---
module: core
audience: user
cross_cutting_user: true
locale: en
canonical_source: core/user-preferences
safe_source_label: "Core · Saved preferences"
version: '1.0'
policy_classification: user_safe
policy_classification_version: in-app-docs-v1
required_permissions: []
tenant_scope: global
---
# Your saved preferences: user guide

## What is saved

The application can remember how you like to work, for example the layout of your lists or the theme you
chose, so that it looks the same on your phone and on your computer. These are your **preferences**. They
are saved on the server, under your account, and only you read and change them.

Preferences are only about appearance and comfort. They never decide what you are allowed to see or do:
your permissions and your access rules stay exactly as they are, whatever your preferences say.

Nothing about how you use the application is recorded in the background. The application does not count
your clicks, does not time you and does not learn from what you do. It keeps what you chose, and nothing
else.

## Your language

The language of the application is part of your account. In the backoffice open the menu under your
avatar, choose **Language**, pick one and confirm: the page reloads in that language. The choice is saved on
the server, so the application, your other devices and the notifications and emails sent to you all use it.

## Your timezone

Dates are stored in UTC and shown to you in your own timezone. The first time you sign in to the backoffice
the application reads the timezone of your browser and saves it, so you do not set anything. To change it,
open your profile from the menu under your avatar, pick a timezone from the list and save. A timezone you
picked yourself is never replaced by the browser. Choose a place, for example Europe/Rome, not an offset:
a place follows daylight saving time by itself.

Until a timezone is saved, dates are shown in UTC. The language and the timezone are independent: the
language decides how a date is written, the timezone decides which hour it shows.

## The language of the content in the backoffice

The backoffice keeps the language of its menus and labels apart from the language of the content you edit.
Content starts in the default language of the installation. To work on another translation, open the menu
under your avatar, choose **Content language** and pick one: the page reloads and every list and form shows
and saves that translation, while menus and labels stay in your own language. The page heading of a form
says which translation you are writing.

In the backoffice every record is listed, translated into that language or not. Where a record has no
translation yet, the list shows its text in the default language with the language written below it, and the
**Without a translation in this language** filter lists only those records. Opening one shows its translated
fields empty, and saving creates the translation. The choice lasts for
this browser session: signing in again, or from another device, starts from the default language.

## Seeing and resetting what is saved

The server lets an application show you everything it remembers about you, grouped by the part of the
application that saved it, and lets you forget one group or all of them. The screen that does this belongs
to the application you use, so look for it in its preferences: it must list what is saved before you change
anything.

- Forgetting one group leaves the other groups as they are.
- Forgetting everything makes the application look like it did the first time you signed in.
- A reset applies on all your devices, because the preferences are kept once, on the server.

## Changes the assistant suggests

The assistant can suggest a change to your preferences or to a view, for example "open lists as cards on
your phone". A suggestion is only a card:

- Nothing changes until you press **Apply**.
- You can **dismiss** it, or ask not to be offered that kind of suggestion again.
- Applying it does the same as changing the setting yourself, and you can change it back at any time.
- The assistant never changes a preference, a record or a permission on its own, and a suggestion never
  gives you access to anything you could not already open.

The assistant says that it is suggesting. If a message ever tells you that a change was already made when
you did not press Apply, nothing was changed.

## Your conversations and what goes away with them

When you delete a conversation with the assistant, its messages, the suggestions in them, its summaries and
its title are removed with it. When your account is deleted, your conversations and the contextual
suggestions held about you are removed too. Contextual suggestions that nobody opened are removed after
seven days in any case.

## Questions

- **Where are my preferences kept?** On the server, under your account, and nowhere else.
- **Can an administrator or another user change them?** Not through the preferences screen: it writes only
  your own account.
- **Does the application track what I click?** No.
- **What if a suggestion is wrong?** Dismiss it. Nothing was changed.
