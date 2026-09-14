# Upstream tracking

Reference project: https://github.com/guillaumemeyer/watermarks-remover

This initial implementation is independently written in PHP and JavaScript. No upstream source code or runtime is bundled. The reference project's APIs and concepts informed the product scope.

Before incorporating upstream code, record the exact commit, copied paths, original copyright notices, license and dependency licenses here. Pin any future service integration to a tested release; never update production from a moving branch.

Review upstream releases and default-branch changes before each release. Adapt changes on a separate branch and validate recovery, Unicode preservation, file integrity and Plugin Check results. The release maintainer performs this review manually before each release; there is no automatic code import.

The WordPress plugin is maintained by Angel Neychev (https://angelneychev.eu, angel.neychev@gmail.com). Joomla is a separate future project.

September 14, 2026 review for 0.4.0: checked the reference main-branch history through 81d808d (September 10). Recent work concerns container metadata, embedded PDF attachments, LaTeX and service dependencies. This release focuses on the WordPress editor and existing text rules; no upstream code or new container formats were incorporated.
