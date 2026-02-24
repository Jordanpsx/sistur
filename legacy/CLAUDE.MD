# SISTUR - Tourism Management Plugin Configuration

## Project Overview
**SISTUR** is a WordPress plugin for comprehensive tourism operations management, including:
- **HR Management** - Employee data and administration
- **Electronic Time Tracking** (Ponto Eletrônico) - Attendance and time logging
- **Inventory Management** - Stock and equipment tracking
- **Leads Management** - Customer prospect tracking

**Language:** Portuguese (pt-BR)
**Type:** WordPress Plugin
**PHP Version:** 7.2+

## Architecture

### Project Structure
- `/includes/` - Main plugin classes (PSR-4 namespace: `SISTUR\`)
- `/assets/` - Frontend stylesheets and scripts
- `/templates/` - HTML templates for admin pages and frontend
- `/docs/` - Documentation and guides
- `sistur.php` - Plugin entry point

### Key Dependencies
- `endroid/qr-code` ^4.0 - QR code generation
- `phpunit/phpunit` ^9.0 - Unit testing (dev)

## Development Guidelines

### Code Standards
1. **PHP Compatibility:** Maintain compatibility with PHP 7.2+
2. **PSR-4 Autoloading:** All classes must follow PSR-4 in `includes/` directory
3. **WordPress Standards:** Follow WordPress coding standards and best practices
4. **Security:** Always sanitize input, validate data, and escape output

### Before Making Changes
- Read /docs/Antigravity.md
- Read the existing code in the relevant module first
- Understand current implementation patterns
- Avoid over-engineering - keep solutions focused and simple
- Don't add features beyond what was requested
- Maintain the Portuguese variable/constant naming convention

### Areas of Focus
- **Banco de Horas** (Hour Banking) - Time tracking system with specific rules
- **Funcionários** (Employees) - Employee management and permissions
- **Aprovações** (Approvals) - Approval workflow system
- **CMV/Estoque** (Inventory) - Stock and equipment management

## Commit Guidelines

When committing:
1. Write clear, descriptive commit messages in English
2. Include the session URL: https://claude.ai/code/session_0145oZWkSkn8Dmdq4GKh74PM
3. Make atomic commits with logical changes only
4. Example: `Fix approval workflow bug in employee time tracking`

## Known Troubleshooting Areas
- See `TROUBLESHOOTING-BANCO-HORAS.md` for hour banking issues
- See `REPROCESSAR_HORAS.md` for hour reprocessing procedures
- Refer to `docs/REFATORACAO-BANCO-HORAS.md` for refactoring documentation

## Do Not
- Introduce breaking changes without discussion
- Modify security-sensitive code without careful review
- Remove functionality without explicit request
- Add complex abstractions for one-time operations
- Make assumptions about Portuguese business logic rules

## Tools & Extensions
- WordPress development tools available via MCP
- PHP linting and validation recommended
- PHPUnit for running test suite: `phpunit`

## Working Branch
- Main development branch: `claude/update-claude-md-qRmAW`
- Always commit to this branch
- Push changes with: `git push -u origin claude/update-claude-md-qRmAW`
