# Revisions Pro Plugin

Transform your Grav CMS into a professional content management powerhouse with **Revisions Pro**, offering comprehensive version control for pages and configurations with a modern, unified interface. Track every change, compare versions visually, and restore content with confidence using this enterprise-grade revision system that integrates seamlessly into your admin workflow.

## Features

### Core Features
- **Content Page Revisions**: Track all changes to page content and frontmatter
- **Configuration Revisions**: Monitor changes to system and site configurations with environment support
- **Plugin Configuration Revisions**: Keep history of plugin and theme configuration changes
- **Visual Diff Viewer**: See exactly what changed between revisions with syntax highlighting
- **One-Click Restore**: Easily restore to any previous version with automatic backup
- **Automatic Cleanup**: Configurable retention policies with scheduled cleanup
- **Smart Storage Management**: Automatic enforcement of revision limits per resource
- **Recoverable Trash Bin**: Preserve deleted pages for easy recovery before permanent removal
- **Multi-Language Support**: Handles content revisions for multi-language sites
- **CLI Tools**: Command-line interface for manual maintenance and cleanup

### Modern UI Features
- **Universal Sliding Panel**: Consistent revision interface across all content types
- **Dark Theme**: Professional dark-themed UI for comfortable viewing
- **Toolbar Integration**: Quick access via history button in admin toolbar
- **Mobile Responsive**: Fully responsive design for all screen sizes
- **HTMX Integration**: Smooth, dynamic loading without full page refreshes
- **Custom Dialogs**: Styled confirmation dialogs instead of browser alerts
- **Session Messages**: Integrated with Grav's notification system
- **Context-Aware UI**: Revisions button only shows when tracking is enabled

## Installation

### Manual Installation

1. Download the plugin from GitHub
2. Extract the zip file to `/user/plugins/revisions-pro`
3. Run `composer install` in the plugin directory to install dependencies

### Admin Plugin Installation

You can install the plugin via the [Grav Admin Plugin](http://learn.getgrav.org/admin-panel/plugins) by searching for "Revisions Pro".

## Configuration

The plugin can be configured through the Admin Panel or by editing `user/config/plugins/revisions-pro.yaml`:

```yaml
enabled: true

# Storage Settings
max_revisions_per_page: 50      # Maximum number of revisions to keep per resource (0 = unlimited)
auto_cleanup: true              # Enable automatic daily cleanup of old revisions
cleanup_older_than: 90          # Delete revisions older than this many days

# Trash Settings
enable_trash: true              # Move deleted pages into a recoverable trash bin
trash_retention_days: 30        # Automatically purge trash items older than this many days (0 disables)
trash_max_items: 0              # Limit how many items stay in trash (0 keeps everything until purged)

# Feature Toggles
track_pages: true               # Track changes to pages
track_config: true              # Track changes to system/site configuration
track_plugins: true             # Track changes to plugin/theme configuration

# UI Settings
show_revision_count: true       # Show revision count indicator in page title
compare_mode: previous          # Default comparison mode (previous/current)
```

### Storage Settings Explained

#### Maximum Revisions per Page
- Limits the number of revisions kept for each resource
- When limit is reached, oldest revisions are automatically deleted
- Set to 0 to keep unlimited revisions (not recommended for production)
- Default: 50 revisions

#### Automatic Cleanup
- When enabled, runs daily at 3:00 AM server time
- Deletes revisions older than the configured age limit
- Runs via Grav's built-in scheduler
- Can also be triggered manually via CLI

#### Cleanup Age Limit
- Revisions older than this many days are deleted during cleanup
- Applies to both automatic and manual cleanup
- Default: 90 days

#### Trash Settings
- **Enable Trash** keeps deleted pages (including their media and revisions) in a recoverable bin instead of removing them immediately.
- **Retention Days** automatically purges trashed pages after the configured number of days. Set to `0` to keep items until manually cleared.
- **Maximum Trash Items** lets you cap how many deleted pages are retained; the oldest entries are purged first when the limit is exceeded.

### Feature Toggles
- Control which types of content are tracked
- Disabling a toggle prevents new revisions from being created
- Also hides the revisions UI for that content type
- Existing revisions are preserved when tracking is disabled

## Usage

### Accessing Revisions

#### Universal Revisions History Button
Click the **History** button (🕐) in the admin toolbar when editing any supported content type. This opens the revision panel from the right side of the screen.

### Viewing Revisions

The revision panel displays:
- **Timestamp** with star indicator for current version
- **User** who made the change
- **File size** of the revision
- **Action buttons**: View, Compare, Restore, Delete

### Comparing Versions

1. Click the **Compare** button on any revision (except the current)
2. A secondary panel slides out showing:
   - Side-by-side diff view
   - Added content highlighted in green
   - Removed content highlighted in red
   - Proper syntax highlighting (YAML for configs, Markdown for pages)

### Restoring Revisions

1. Click the **Restore** button on any revision
2. Confirm in the custom dialog
3. The current version is automatically backed up before restoration
4. Page reloads with a success message showing which revision was restored

### Managing Deleted Pages

Deleted pages automatically appear in the **Trash** panel (🗑) on the Pages list toolbar. From there you can:

- Review metadata for each deleted page, including original route, user, and deletion time.
- Restore to the original path or choose a new parent/slug combination before copying the content back into `user/pages`.
- Overwrite an existing page safely—Revisions Pro captures it into trash before replacing it.
- Permanently delete individual items or empty the entire trash bin in one action.

Retention limits from the plugin configuration are enforced whenever the trash list is accessed, ensuring old entries are purged automatically.

## CLI Commands

The plugin provides a command-line interface for maintenance tasks:

### Cleanup Command

Remove old revisions based on age:

```bash
# Use default settings from configuration
bin/plugin revisions-pro cleanup

# Specify custom age limit (overrides configuration)
bin/plugin revisions-pro cleanup --days=30

# Preview what would be deleted without actually deleting
bin/plugin revisions-pro cleanup --dry-run

# Run quietly (no output except errors)
bin/plugin revisions-pro cleanup --quiet

# Specify environment
bin/plugin revisions-pro cleanup --env=production
```

#### Command Options

- `--days=DAYS` - Delete revisions older than this many days (overrides configuration)
- `--dry-run` - Show what would be deleted without actually deleting
- `--quiet` - Suppress all output except errors
- `--env=ENV` - Use specific environment configuration
- `--help` - Display help information

#### Examples

```bash
# Delete revisions older than 7 days
bin/plugin revisions-pro cleanup --days=7

# Preview cleanup for production environment
bin/plugin revisions-pro cleanup --env=production --dry-run

# Run cleanup silently in a cron job
bin/plugin revisions-pro cleanup --quiet --days=30
```

### Scheduled Cleanup

When `auto_cleanup` is enabled, the plugin automatically runs cleanup daily at 3:00 AM server time using Grav's scheduler. To run the scheduler:

```bash
# Run scheduler once
bin/grav scheduler

# Run scheduler as a daemon (requires supervisor or similar)
bin/grav scheduler -i
```

For production environments, set up a cron job to run the scheduler:

```cron
# Run Grav scheduler every minute
* * * * * cd /path/to/grav && bin/grav scheduler 1>> /dev/null 2>&1
```

## Storage Architecture

### Page Revisions
Stored alongside page files: `page.md.YYYYMMDD-HHMMSS.rev`

### Configuration Revisions
Stored alongside config files (respecting environment paths):
- System/Site/etc.: `/user/config/system.yaml.YYYYMMDD-HHMMSS.rev`
- Plugin configs: `/user/config/plugins/plugin-name.yaml.YYYYMMDD-HHMMSS.rev`
- Theme configs: `/user/config/themes/theme-name.yaml.YYYYMMDD-HHMMSS.rev`
- Environment-specific: `/user/env/production/config/system.yaml.YYYYMMDD-HHMMSS.rev`

### Storage Management
- **Revision Limit**: Automatically enforces `max_revisions_per_page` limit
- **Age-based Cleanup**: Removes revisions older than `cleanup_older_than` days
- **Index Maintenance**: Central index tracks all revisions for efficient management

## Technical Details

### YAML Handling
The plugin uses Grav's native YAML handling (`Grav\Common\Yaml`) for all YAML operations, ensuring consistency with the framework.

### Revision Index
A centralized index (`/user/data/revisions/index.json`) tracks all revisions for quick lookup and management.

### AJAX Architecture
- **Endpoint**: `/admin/revisions-api`
- **Actions**: `list`, `view`, `diff`, `restore`, `delete`
- **Response Format**: HTML fragments for HTMX or JSON for restore/delete operations

## Development

### Events

The plugin provides several events for developers:

- `onRevisionCreate`: Fired when a new revision is created
- `onRevisionRestore`: Fired when a revision is restored
- `onRevisionDelete`: Fired when a revision is deleted

### API

```php
// Get revision manager
$revisionManager = $grav['plugins']->get('revisions-pro')->revisionManager;

// Get revisions for a page
$revisions = $revisionManager->getRevisions($page);

// Create a manual revision
$revisionManager->createRevision($object, 'page');

// Restore a revision
$revisionManager->restoreRevision($revisionId);

// Get revisions for config
$revisions = $revisionManager->getRevisionsForRoute('system', 'config-system');
```

### Extending to New Content Types

To add support for new content types:

1. **Update JavaScript route detection** in `loadPageRevisions()`:
```javascript
} else if (pathname.match(/\/your-type\//)) {
    route = extractRoute();
    type = 'your-type';
}
```

2. **Handle save events** in `onAdminAfterSave()`:
```php
} elseif ($object instanceof YourType) {
    $type = 'your-type';
    $result = $this->revisionManager->createRevision($object, $type);
}
```

3. **Implement restore logic** if needed in RevisionManager

## Requirements

- Grav CMS 1.7+
- PHP 7.4+
- Admin Plugin
- Scheduler Plugin (for automatic cleanup)

## Troubleshooting

### Revisions Not Being Created
1. Check that the plugin is enabled in configuration
2. Verify the specific tracking toggle is enabled (track_pages, track_config, track_plugins)
3. Check file permissions - the web server must be able to write to content directories
4. Review Grav logs for any error messages

### Cleanup Not Running Automatically
1. Ensure `auto_cleanup` is enabled in configuration
2. Verify the Grav scheduler is running (check cron job)
3. Check that the Scheduler plugin is installed and enabled
4. Review logs for any scheduler errors

### CLI Command Errors
1. For "environment not defined" errors, use the `--env` option
2. Ensure you're running commands from the Grav root directory
3. Check that the plugin is installed correctly with all dependencies

### Storage Issues
1. Monitor disk space - revisions can consume significant storage
2. Adjust `max_revisions_per_page` to limit storage usage
3. Reduce `cleanup_older_than` for more aggressive cleanup
4. Run manual cleanup if needed: `bin/plugin revisions-pro cleanup --days=7`

### Performance Considerations
- Large numbers of revisions may slow down the revision panel loading
- Consider reducing `max_revisions_per_page` for better performance
- The cleanup process may be resource-intensive on sites with many revisions

## License

MIT License. See [LICENSE](LICENSE) file for details.
