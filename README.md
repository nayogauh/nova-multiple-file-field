# Nova Multiple File Field

A [Laravel Nova](https://nova.laravel.com) field to **upload, preview, download and delete multiple files** on a single resource attribute.

- ✅ Compatible with **Nova 4 & Nova 5** (Vue 3)
- ✅ Compatible with **Laravel 9 → 13** (PHP 8.0+)
- ✅ Drag & drop, image thumbnails, per-file delete
- ✅ Stores everything in **one JSON column** — no extra table or migration
- ✅ Works with any filesystem disk (`local`, `public`, `s3`, …)

---

## Installation

```bash
composer require nayogauh/nova-multiple-file-field
```

The service provider is auto-discovered and the **compiled assets are already shipped**
in `dist/`, so the field works immediately — no build step required.

> **Before the package is published on [Packagist](https://packagist.org)**, the command
> above cannot resolve it yet. Install it from your repository or a local path instead by
> adding this to your **Laravel app's** `composer.json`:
>
> ```jsonc
> "repositories": [
>     { "type": "vcs", "url": "https://github.com/nayogauh/nova-multiple-file-field" }
>     // — or, for a local clone —
>     // { "type": "path", "url": "../nova-multiple-file-field" }
> ],
> ```
>
> then run `composer require nayogauh/nova-multiple-file-field:@dev`.

### Database

The field stores all of a resource's files as a JSON array in **a single column**.
Add a `json` (or `text`) column and cast it to `array` on your model.

```php
// Migration
Schema::table('posts', function (Blueprint $table) {
    $table->json('attachments')->nullable();
});
```

```php
// App\Models\Post
protected $casts = [
    'attachments' => 'array',
];
```

> A `text` column works too — the field detects whether the attribute is cast to
> `array`/`json` and stores either a native array (cast) or a JSON string (no cast).

---

## Usage

```php
use Nayogauh\MultipleFile\MultipleFile;

public function fields(NovaRequest $request)
{
    return [
        // ...
        MultipleFile::make('Attachments')
            ->disk('public')          // storage disk (default: "public")
            ->path('attachments')     // sub-directory on the disk (default: "/")
            ->maxFiles(5)             // optional: limit the number of files
            ->maxFileSize(10240)      // optional: max size per file in KB (10 MB)
            ->acceptedTypes('image/*,.pdf') // optional: HTML accept filter
            ->prunable(),             // optional: enable disk cleanup (see below)
    ];
}
```

### What gets stored

Each file is saved to the disk with a unique UUID filename, and an entry like this
is appended to the JSON column:

```json
[
  {
    "name": "invoice-2026.pdf",
    "path": "attachments/4b9c…e2.pdf",
    "disk": "public",
    "size": 84213,
    "mime": "application/pdf"
  }
]
```

On the detail/index views the field resolves a public URL (or a temporary signed
URL for `s3`) for each entry automatically.

### Custom storage — handle each file yourself

By default the field stores every file automatically into the JSON column. If you'd
rather control storage yourself (e.g. iterate the files and call `->store()` on each),
pass a callback to `->store()`. Inside it, `$request->{attribute}` is an **array of
`UploadedFile`**, so you can `foreach` over it:

```php
use Nayogauh\MultipleFile\MultipleFile;

MultipleFile::make('Word File', 'word_file')
    ->store(function ($request, $model) {
        $paths = [];

        foreach ($request->file('word_file') ?? [] as $file) {
            $paths[] = $file->store('imports', 'public');
        }

        // Return [column => value] pairs to write on the model:
        return ['word_file' => json_encode($paths)];
    });
```

The callback may return:

| Return value | Effect |
|---|---|
| `['col' => $value, ...]` | assigns each `col` on the model |
| a scalar (e.g. a string) | assigned to the field's own attribute |
| `null` or `true` | you persisted everything yourself; nothing is written |

> In this mode you have **full control**: the automatic JSON storage and the
> per-file delete/keep logic are skipped, so handle existing files as you see fit.

### Using the field inside a Nova Action

When the field is added to a **Nova Action**, it does **not** store anything
automatically. Instead, `$fields->{attribute}` gives you a plain **array of
`UploadedFile`** that you iterate and persist yourself:

```php
use Laravel\Nova\Fields\ActionFields;
use Illuminate\Support\Collection;
use Nayogauh\MultipleFile\MultipleFile;

class ImportFiles extends Action
{
    public function fields(NovaRequest $request)
    {
        return [
            MultipleFile::make('Files', 'files')
                ->acceptedTypes('.doc,.docx'),
        ];
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $files = $fields->get('files');   // array of UploadedFile (also: $fields->files)

        $absolutePaths = [];

        foreach ($files as $file) {
            $path = $file->store('imports');                     // default disk
            $absolutePaths[] = storage_path('app/' . $path);

            // One-to-many: attach a related record to each model.
            foreach ($models as $model) {
                $model->documents()->create([
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                ]);
            }
        }
    }
}
```

The field's value is **always an array** of `Illuminate\Http\UploadedFile` (empty if
nothing was uploaded), so the `foreach` is always safe — no `?? []` needed. Access it
with `$fields->get('files')` or the equivalent `$fields->files` / `$fields['files']`.

### Deleting individual files

On the edit form every file is listed with its own 🗑 button. Removing a file and
saving the resource deletes it from the disk and from the JSON column — the other
files are kept untouched.

### Pruning files when the model is deleted

Calling `->prunable()` flags the field, but Nova's resource deletion does not know
how to walk a JSON column. Clean the disk up from your model instead:

```php
use Illuminate\Support\Facades\Storage;

protected static function booted()
{
    static::deleting(function (Post $post) {
        foreach ((array) $post->attachments as $file) {
            if (! empty($file['path'])) {
                Storage::disk($file['disk'] ?? 'public')->delete($file['path']);
            }
        }
    });
}
```

---

## Building the front-end assets

The compiled assets live in `dist/` and are committed with the package, so you do **not**
need to build anything to use it.

The components are self-contained (they rely on Nova's globally-registered `DefaultField`
/ `PanelItem` components and the global `Vue`), so recompiling does **not** require the
private `laravel/nova` npm helpers:

```bash
npm install
npm run dev      # rebuild on change (watch)
npm run build    # production build → dist/js/field.js + dist/css/field.css
```

The build uses [Vite](https://vitejs.dev) and emits a single IIFE bundle that works in
both Nova 4 and Nova 5 (both ship Vue 3).

---

## License

MIT
