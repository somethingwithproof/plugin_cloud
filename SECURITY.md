# Security policy

## Supported versions

No version of this plugin has been released. This policy applies to the design documents now and to released code later.

## Reporting a vulnerability

Report vulnerabilities privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**. Do not open a public issue.

Include what is affected, how to reproduce it, and the versions of Cacti and PHP and the cloud provider involved. You will get an acknowledgement on GitHub, and fixes are prepared privately until a release is available.

Vulnerabilities in Cacti core or in another Cacti plugin belong to that project's own disclosure process, not this repository. Vulnerabilities in a cloud provider's own services belong to that provider's disclosure program.

Never include a working cloud credential in a report. If one was exposed, revoke it at the provider first.

## Design-level security

The security boundaries this design relies on are described in [docs/PLAN.md](docs/PLAN.md#security). Weaknesses found in the design itself, before any code exists, may be reported publicly as issues with the `area: security` label.
