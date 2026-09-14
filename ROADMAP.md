# Next steps

Initially recorded on September 11, 2026. On September 13, Angel agreed to combine these additions into 0.4.0. On September 14, Angel requested implementation.

Version 0.4.0 is implemented, installed locally and published to WordPress.org on September 14, 2026 (SVN revision 3694644).

1. Implemented: block editor panel. Preview unsaved title, content and excerpt; apply to the editor with WordPress Undo and normal Save. Reject stale previews and block structures that cannot be preserved exactly.
2. Implemented: separate invisible-character and dash settings, stored per administrator and used by workspace, paste and editor previews.
3. Implemented: Load example button for plain text and HTML, with confirmation before replacing entered text.

Integration and browser checks passed, directory screenshots were updated, and the ZIP was built. See VALIDATION-0.4.0.md for evidence and limitations. All three agreed additions are included in the published release. Add further features only after agreeing on the next scope with Angel.

## Future additions

Recorded at Angel's request on September 14, 2026. These items are planned only; implementation has not been requested. Choose the release scope, version number and timing after collecting feedback from actual use.

1. Selected-content cleanup. Preview and clean only a selected block or highlighted text in the editor. Suggested first priority. Preserve surrounding content, markup and the editor's undo behavior.
2. Bulgarian interface translation. Translate the plugin's controls, messages and help text using the existing WordPress translation support.
3. Media Library shortcut. Add a metadata inspection button directly in the Media Library, leading to the existing review and cleanup workflow.

Start development only when Angel asks to proceed.
