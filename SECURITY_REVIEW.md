# Security Review for Public Release

This checklist is intended to be completed before publishing the project to a public GitHub repository or creating a release package.

## Review Goals

- Prevent disclosure of credentials, secrets, or test infrastructure details
- Prevent disclosure of instance-specific URLs or tokenized worker endpoints
- Ensure the release package contains only extension files needed for FreshRSS

## Sensitive Information Checklist

- API keys are never committed to the repository
- No real queue worker URLs with tokens appear in docs or screenshots
- No production, home-lab, or LAN URLs appear in public documentation
- No personal filesystem paths appear in docs, screenshots, or examples
- No cookies, sessions, authorization headers, or bearer tokens appear in examples
- No private diagnostics or logs are included in the repository

## Packaging Checklist

- `.DS_Store`, editor files, and local artifacts are excluded
- Release zip contains the `xExtension-AutoLabel/` directory only
- `docs/`, `scripts/`, and other repo-only files are not required inside the release zip
- The packaged extension still contains metadata, controllers, views, static assets, i18n files, and runtime PHP code

## Product Review Checklist

- Anonymous users cannot access the AutoLabel dashboard
- Admin-only data is not shown to regular users
- Queue worker URL is visible only to administrators
- Public docs explain current limitations honestly
- Public docs do not promise unsupported behavior

## Recommended Audit Commands

Run:

```bash
./scripts/release-audit.sh
```

Then manually inspect:

- `README.md`
- `docs/README.zh-CN.md`
- `docs/README.fr.md`
- generated release notes
- release zip file contents

