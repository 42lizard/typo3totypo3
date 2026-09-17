# Documentation site

The Markdown guides in `docs/` are the source for
[GitHub Pages](https://42lizard.github.io/typo3totypo3/). MkDocs provides navigation
and search; screenshots are stored in `docs/images/`. Internal agent instructions
are excluded from the generated site.

## Preview locally

From the repository root, using Python 3.12 or newer:

```bash
python3 -m venv .venv-docs
.venv-docs/bin/pip install -r requirements-docs.txt
.venv-docs/bin/mkdocs serve
```

Open the local address printed by MkDocs. To validate navigation, links and
anchors and build the static site:

```bash
.venv-docs/bin/mkdocs build --strict
```

Generated files in `site/` and the virtual environment are ignored by Git. Add new
guides to `nav` in `mkdocs.yml`; use relative Markdown links and image paths so the
same sources work both on GitHub and on the published site.

## Deployment

The `Documentation` GitHub Actions workflow validates documentation pull requests.
Changes to the guides, site configuration, dependencies or workflow on `main`
build and deploy the site automatically. It can also be triggered manually.
Only `main` deploys; pull requests have no Pages write permissions.

Repository **Settings → Pages → Build and deployment → Source** must be set to
**GitHub Actions**. The workflow publishes only the generated `site/` directory
using the `github-pages` environment. No custom deployment credentials or
separate publishing branch are required.
