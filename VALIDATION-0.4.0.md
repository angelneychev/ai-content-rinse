# Version 0.4.0 validation

September 14, 2026. Local WordPress 7.1, PHP 8.3, Chrome.

## Automated checks

- 24 original integration assertions and 45 media/text regression assertions passed.
- 35 new PHP integration assertions passed: all four rule combinations, exact before/after segments, protected markup/code/emoji, saved per-user rules, invalid settings, unsaved editor fields, input bounds, builder rejection, anonymous permissions, frozen workspace proposals and restoration.
- 12 JavaScript tests passed: current unsaved fields, single editor edit, stale preview rejection, in-flight edits, changed post ID, save/lock/code-mode rejection, invalid/bound blocks, serialization mismatch, network error recovery and disabled Apply for no findings.
- Plugin Check static checks: 0 errors, 0 warnings.
- PHP and JavaScript syntax, release copy/version validation and installable ZIP allowlist checks passed.

## Actual browser checks

- Loaded the sample and previewed three changes: two dashes and one zero-width space.
- Disabled dash replacement and confirmed only the invisible character was removed. Re-enabled it and reloaded to confirm preference persistence.
- HTML paste preserved WordPress block comments, a quoted attribute and code while changing only the selected text characters.
- Choosing Keep my text retained the entered text; Replace with example inserted the sample.
- Edited an unsaved title in a disposable draft. The preview included that title, the content and the excerpt. Apply changed all three fields in the editor, preserving a code block.
- One WordPress Undo restored all three fields without discarding the earlier unsaved title edit. Redo reapplied the cleanup.
- Saved the draft through WordPress and reloaded: the cleaned title/content/excerpt persisted and the code remained intact.
- Media preview still showed the injected demonstration metadata.
- Captured and visually reviewed four screenshots from the updated interface.

## Scope

The editor panel supports administrators using the visual block editor for posts/pages. It does not add a Classic Editor toolbar button. Invalid/bound blocks and content that cannot be parsed and serialized exactly are rejected. WordPress autosave remains active; applying cleanup does not itself publish, and does not add a plugin History recovery record. Editor input is limited to 1 MB across the three fields.

Following Angel's publication request, version 0.4.0 and the four screenshots were published to WordPress.org on September 14, 2026, in SVN revision 3694644. GitHub Plugin checks passed for implementation commit b99194b9ca73e037bc1c0fe2952f08e634be91d8. SVN trunk and tags/0.4.0 were compared against all 10 runtime source files before deployment and matched exactly.
