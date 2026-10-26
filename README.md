<div align="center">

# Sigil Pro

[![License](https://img.shields.io/badge/LICENSE-GPL--3.0-5C9E31?style=for-the-badge)](LICENSE)
[![Built by](https://img.shields.io/badge/BUILT%20BY-JEAN%20GALEA-8A2BE2?style=for-the-badge)](https://github.com/jgalea)

**Add-on for the Sigil two-factor plugin: method policy, branding, settings portability and more. Free, no licence key.**

</div>

Sigil Pro extends [Sigil](https://wordpress.org/plugins/sigil-2fa/), which has to be installed and active first.

## What it adds

- Method policy: choose which second factors each role may enrol and use.
- Branding: your wording, sender and CSS on the challenge screen and code emails.
- Settings export and import, without accounts, credentials or secrets.
- Two-factor code on password reset.
- A destination for people who have just finished setup.
- Excuse a user's next sign-in once, from the Users screen.
- Two-factor history on each profile.

## Install

Download `sigil-2fa-pro.zip` from the latest release, upload it under Plugins, Add New, and activate it. Later releases show up as normal updates on the Plugins screen.

If you ran the earlier combined build, update Sigil to 0.5 or later first, then install this. Your saved settings carry over.

## Development

The tests run against the free plugin, which supplies the test runner. Check it out next to this repo (or point `SIGIL_FREE_DIR` at it), then:

```
npx @wordpress/env start
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/sigil-2fa-pro ../sigil-2fa/vendor/bin/phpunit
```

`./build.sh` writes `dist/sigil-2fa-pro.zip`.

## License

GPL-3.0-or-later.
