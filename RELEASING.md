# Publishing AI Content Rinse

## Initial WordPress.org submission

1. Run the integration suites and Plugin Check on the release candidate.
2. Run `php tools/package.php`. This validates the version and writes only runtime files into `.build/ai-content-rinse-VERSION.zip`.
3. Submit that ZIP at https://wordpress.org/plugins/developers/add/ using the owner account `angelneychev`. Request the slug `ai-content-rinse`; the directory may initially derive a longer slug from the display name. Verify the assigned slug before approval.
4. Respond to the review feedback and upload a revised ZIP when requested. A GitHub repository alone does not publish the plugin to WordPress.org.
5. After approval, verify SVN access and the final directory slug. If it differs, align the text domain, plugin directory and deployment configuration before the first public release.

## Publishing approved versions

The repository includes a tag-triggered SVN deployment workflow. It is disabled until the repository variable `WORDPRESS_ORG_APPROVED` is set to `true` after directory approval.

Required repository secrets:

- `SVN_USERNAME`: WordPress.org username.
- `SVN_PASSWORD`: the SVN password generated in that WordPress.org profile (not a GitHub password).

Never commit credentials. Secrets are not automatically shared with other repositories.

Update the plugin header, asset versions, displayed version and readme stable tag together. Add a changelog entry, run checks and push a matching version tag, for example `v0.3.1`. The workflow checks the tag/version match, builds the exact release package and runs Plugin Check before deploying through the pinned 10up action. It requires the SVN repository to already exist.

The `.distignore` file excludes tests, build scripts, development documentation, Git metadata and workflows from SVN deployment. The explicit package allowlist is the reference for ZIP contents.

## Reference review

Before each release, follow UPSTREAM.md. Review changes manually and adapt only relevant behavior, retaining attribution and license terms for any code actually incorporated. Do not fetch executable upstream code from users' WordPress installations.

## Official references

- Submission: https://wordpress.org/plugins/developers/add/
- Guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- SVN: https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/
