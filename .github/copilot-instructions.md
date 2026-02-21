# GitHub Copilot Instructions for WebMCP Omeka S Module

This document provides specific instructions for GitHub Copilot when working on the WebMCP Omeka S module.

## Project Overview

WebMCP is an Omeka S module that exposes management capabilities to AI agents through a browser-side implementation of the Model Context Protocol (MCP). It is developed by Área de Tecnología Educativa (ATE).

## Coding Standards

### PSR-2 Coding Standards
- Follow [PSR-2 Coding Standards](https://www.php-fig.org/psr/psr-2/) for all PHP code.
- Use 4 spaces for indentation.
- Use `CamelCase` for class names and `camelCase` for methods and variables (as per PSR-2 and Laminas conventions).
- All PHP files must have `declare(strict_types=1);`.

### Omeka S & Laminas Best Practices
- Use Laminas (formerly Zend Framework) components and Omeka S APIs.
- Prefer Omeka S `ApiManager` for data operations.
- Use proper dependency injection via the `ServiceLocator` or `__construct` if applicable.
- Follow the MVC pattern as implemented in Omeka S.

### Code Structure
- Source code lives in the `src/` directory, following PSR-4 (`WebMCP` namespace).
- Configuration is in `config/module.config.php` and `config/module.ini`.
- JavaScript assets are in `asset/js/`.
- PHPUnit tests are in `test/WebMCPTest/`.
- Translations are in `language/`.

## Language Requirements

### Source Code
- Write all source code (identifiers, comments, docblocks) in **English**.
- Use clear, descriptive names that self-document the code.

### User-Facing Content
- All user-facing strings (labels, messages, titles) must be in **Spanish**.
- Use the `translate()` helper in views or the translator service in controllers/forms.
- **Always update translations** after adding new translatable strings using the provided Makefile commands.
- Verify no untranslated strings remain using `make check-untranslated`.

## Development Workflow

### Test-Driven Development (TDD)
- Write tests BEFORE implementing features when possible.
- Use PHPUnit for testing. Tests are located in `test/WebMCPTest/`.
- Ensure tests cover both successful paths and edge cases (e.g., permission denied, not found).
- Run tests with `make test`.

### Code Quality
- Run `make lint` to check PHP code style (PSR-2).
- Run `make fix` to automatically fix code style issues.
- Ensure all linting passes before committing.

### Environment
- The project includes a `docker-compose.yml` for local development.
- Start environment with `make up`.
- Access Omeka S admin to enable the module after development.

## Security

### Input/Output Handling
- **Always** validate user inputs using Laminas Validators.
- Use CSRF protection for all forms and sensitive proxy actions (already implemented in `WebMCPProxyController`).
- **Always** escape output in views using appropriate helpers.
- Use Omeka S's built-in ACL and session management.

### Best Practices
- Never bypass the Omeka S API for database operations if possible.
- Ensure only authenticated admin users can access WebMCP proxy endpoints.

## Frontend Technologies

- Implementation uses **Vanilla JavaScript** (ES6+).
- Interacts with the backend via the `/admin/webmcp/proxy` endpoint.
- Uses standard browser APIs for the Model Context Protocol implementation.

## Common Patterns

### Adding a New Controller Action
1. Define the route in `config/module.config.php`.
2. Add the action to the controller in `src/Controller/`.
3. Ensure proper permission checks and CSRF validation.
4. Write a unit test in `test/WebMCPTest/Controller/`.

### Adding Translatable Strings
1. Use `// @translate` comment for strings that are not directly in `translate()` calls but need extraction.
2. Run `make i18n` to extract, merge, and compile translations.

## Documentation

- Update PHPDoc blocks for all modified functions/classes.
- Ensure the main `Module.php` docblock stays updated.

## Quick Reference

### Makefile Commands
- `make up` - Start Docker environment.
- `make down` - Stop Docker environment.
- `make test` - Run PHPUnit tests.
- `make lint` - Check PHP code style (PSR-2).
- `make fix` - Auto-fix PHP code style.
- `make i18n` - Full translation workflow (extract, merge, compile).
- `make check-untranslated` - Check for untranslated Spanish strings.

### Key Principles
1. **Omeka S First**: Use Omeka S APIs and Laminas components.
2. **Security First**: Validate CSRF, check permissions, sanitize input.
3. **Test First**: Always include unit tests for new logic.
4. **Spanish UI**: All user-facing text in Spanish.
5. **English Code**: All code and comments in English.
