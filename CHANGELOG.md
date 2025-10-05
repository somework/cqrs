# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2024-01-01
### Added
- Command, query, and event buses wired to Symfony Messenger with automatic handler discovery.
- Metadata stamps and providers to append correlation and context details to dispatched messages.
- Async bus configuration helpers to route commands and events through worker transports.
- Console tooling for listing registered handlers and generating message skeletons.
- A generator that scaffolds commands, queries, events, and matching handlers.
- Foundational test suite covering registry discovery, console tooling, and service configuration.

[0.1.0]: https://github.com/somework/cqrs-bundle/releases/tag/0.1.0
