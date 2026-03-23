# Awesome-List Submission Descriptions

Ready-to-use entries for awesome-list pull request submissions. Each entry follows the exact format required by the target repository's contribution guidelines.

## awesome-php (ziadoz/awesome-php)

**Target section:** Queues

The Queues section contains messaging-related libraries (RabbitMQ, Bernard, etc.). There is no dedicated CQRS section. If the maintainer prefers, suggest creating a "Messaging / CQRS" subsection.

**Entry (place alphabetically within the section):**

```markdown
- [SomeWork CQRS Bundle](https://github.com/somework/cqrs) - Typed Command, Query, and Event buses on top of Symfony Messenger with attribute-based handler discovery.
```

Note: Place after entries starting with "R" and before entries starting with "T" (alphabetical by display name).

## awesome-symfony (sitepoint-editors/awesome-symfony)

**Target section:** Queues

**Entry (place alphabetically within the section):**

```markdown
- [SomeWork CQRS Bundle](https://github.com/somework/cqrs) - Typed Command, Query, and Event buses on top of Symfony Messenger with attribute-based handler discovery and per-message stamp pipeline.
```

Note: Place after entries starting with "R" and before entries starting with "T" (alphabetical by display name).

## Submission checklist

- [ ] Fork repository
- [ ] Add entry in alphabetical order within target section
- [ ] Verify description ends with period
- [ ] Verify no self-promotional language ("best", "powerful", etc.)
- [ ] Open PR with title "Add SomeWork CQRS Bundle"
- [ ] Link to repository in PR description

## Alternative descriptions

In case maintainers request changes, shorter variants:

- "A Symfony Messenger wrapper providing typed CQRS buses with auto-discovered handlers."
- "Command, Query, and Event buses for Symfony Messenger with attribute-based handler registration."
