# Frontpage Markdown Content

This directory contains Markdown files for the portal frontpage content.

**Location:** `tk/includes/content/frontpage/`

## Files

### Left Column Content
- `left-column.en.md` - English version
- `left-column.es.md` - Spanish version
- `left-column.pt.md` - Portuguese version (create as needed)
- `left-column.fr.md` - French version (create as needed)

### Newsbar Content
- `newsbar.en.md` - English version
- `newsbar.es.md` - Spanish version
- `newsbar.pt.md` - Portuguese version (create as needed)
- `newsbar.fr.md` - French version (create as needed)

## Usage

Content is automatically rendered based on the user's language preference (`$LANG_TAG`).

If a language-specific file doesn't exist, it falls back to English (`*.en.md`).

## Editing Content

Simply edit the Markdown files directly! No PHP knowledge required.

### Supported Markdown Syntax

- **Headers**: `# H1`, `## H2`, `### H3`
- **Bold**: `**bold text**`
- **Italic**: `*italic text*`
- **Links**:
  - Regular: `[link text](https://example.com)`
  - Open in new tab: `[link text](https://example.com){:target="_blank"}`
- **Lists**:
  - Unordered: `- item` or `* item`
  - Ordered: `1. item`, `2. item`
- **Horizontal rule**: `---`

### Example

```markdown
# Welcome to MyCoPortal

This is a paragraph with **bold** and *italic* text.

- First item
- Second item
- Third item

[Visit our website](https://example.com)
[External link](https://example.com){:target="_blank"}

---

More content here.
```

## Benefits

✅ **Easy to edit** - No HTML or PHP knowledge required  
✅ **Version control friendly** - Plain text files work great with Git  
✅ **Multi-language support** - Automatic language detection  
✅ **Clean separation** - Content separated from presentation  
✅ **Fast** - Simple, lightweight rendering  

## Migration from Old System

The old system used:
- `/var/www/html/portal/assets/custom/includes/maincontent.php` (hardcoded HTML/PHP)
- `/var/www/html/portal/assets/custom/includes/newsbar.php` (hardcoded HTML)

The new system uses:
- `tk/content/frontpage/left-column.{lang}.md` (Markdown)
- `tk/content/frontpage/newsbar.{lang}.md` (Markdown)
- `tk/includes/markdown_content.php` (Renderer)

To switch to the new system, update `index.php` to include:
```php
include($SERVER_ROOT.'/assets/custom/includes/maincontent_new.php');
```

Instead of:
```php
include($SERVER_ROOT.'/assets/custom/includes/maincontent.php');
```

