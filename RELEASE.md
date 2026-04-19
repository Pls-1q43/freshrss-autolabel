# Release Workflow

## Repository Preparation

1. Initialize git if needed.
2. Review the output of `./scripts/release-audit.sh`.
3. Confirm that public documentation is complete in English, Chinese, and French.
4. Confirm that no admin-only or environment-specific information remains in docs or assets.

## Packaging

Generate the release archive with:

```bash
./scripts/package-release.sh
```

Expected output:

```text
dist/xExtension-AutoLabel-<version>.zip
```

## GitHub Release

Suggested first release flow:

1. Create a new public repository named `freshrss-autolabel`
2. Push the local `main` branch
3. Create a release using the version from `metadata.json`
4. Upload the generated zip archive
5. Use the English README summary in the release notes and link to the Chinese and French documentation

## Recommended Release Notes Sections

- Highlights
- Supported providers and modes
- Installation
- Queue and maintenance behavior
- Known limitations

