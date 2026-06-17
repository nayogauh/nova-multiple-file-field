<?php

namespace Nayogauh\MultipleFile;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;

class MultipleFile extends Field
{
    /**
     * The Vue component used to render the field.
     *
     * @var string
     */
    public $component = 'multiple-file';

    /**
     * Display the field with full text on the index view.
     *
     * @var string
     */
    public $textAlign = 'left';

    /**
     * The storage disk the files live on.
     *
     * @var string
     */
    public $disk = 'public';

    /**
     * The directory (relative to the disk) where files are stored.
     *
     * @var string
     */
    public $storagePath = '/';

    /**
     * Whether files should be removed from the disk when the model is deleted.
     *
     * @var bool
     */
    public $prunable = false;

    /**
     * The maximum number of files allowed (null = unlimited).
     *
     * @var int|null
     */
    public $maxFiles = null;

    /**
     * The maximum size in kilobytes accepted per file (null = no client limit).
     *
     * @var int|null
     */
    public $maxFileSize = null;

    /**
     * The accepted mime types / extensions (e.g. "image/*,.pdf").
     *
     * @var string|null
     */
    public $acceptedTypes = null;

    /**
     * A custom callback used to store the uploaded files instead of the default
     * JSON behaviour. Receives ($request, $model, $attribute, $requestAttribute).
     *
     * @var callable|null
     */
    public $storeCallback = null;

    /**
     * Provide a custom storage callback, giving you full control over how each
     * uploaded file is persisted (and what gets written to the model).
     *
     * Inside the callback, `$request->{attribute}` is an array of UploadedFile,
     * so you can iterate it and call ->store() on each file yourself:
     *
     *     MultipleFile::make('Word File', 'word_file')->store(function ($request, $model) {
     *         $paths = [];
     *         foreach ($request->file('word_file') ?? [] as $file) {
     *             $paths[] = $file->store('imports', 'public');
     *         }
     *         return ['word_file' => json_encode($paths)];
     *     });
     *
     * The callback may return:
     *   - an array of [column => value] pairs to set on the model, or
     *   - a single scalar value to assign to this field's attribute, or
     *   - null/true to indicate you handled persistence yourself.
     *
     * @return $this
     */
    public function store(callable $storeCallback)
    {
        $this->storeCallback = $storeCallback;

        return $this;
    }

    /**
     * Set the storage disk used to store the files.
     *
     * @return $this
     */
    public function disk(string $disk)
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Set the directory, relative to the disk root, where files are stored.
     *
     * @return $this
     */
    public function path(string $path)
    {
        $this->storagePath = $path;

        return $this;
    }

    /**
     * Mark the field's files as prunable (deleted with the parent model).
     *
     * @return $this
     */
    public function prunable(bool $prunable = true)
    {
        $this->prunable = $prunable;

        return $this;
    }

    /**
     * Limit the maximum number of files that may be attached.
     *
     * @return $this
     */
    public function maxFiles(int $max)
    {
        $this->maxFiles = $max;

        return $this;
    }

    /**
     * Limit the maximum size (in kilobytes) accepted per file.
     *
     * @return $this
     */
    public function maxFileSize(int $kilobytes)
    {
        $this->maxFileSize = $kilobytes;

        return $this;
    }

    /**
     * Restrict the accepted mime types / extensions, e.g. "image/*,.pdf".
     *
     * @return $this
     */
    public function acceptedTypes(string $types)
    {
        $this->acceptedTypes = $types;

        return $this;
    }

    /**
     * Validation rules applied to EACH uploaded file individually
     * (validated against "{attribute}.*").
     *
     * @var array
     */
    public $perFileRules = [];

    /**
     * Names of Laravel rules that operate on a SINGLE file and must therefore
     * be validated against each element of the uploaded array ("{attribute}.*")
     * instead of the array itself.
     *
     * @var array<int, string>
     */
    protected $singleFileRuleNames = [
        'file', 'image', 'mimes', 'mimetypes', 'dimensions', 'extensions',
    ];

    /**
     * Add validation rules applied to EACH uploaded file individually.
     *
     * Use this for per-file constraints whose name Laravel can't unambiguously
     * route (e.g. a size limit), since those would otherwise be applied to the
     * whole array:
     *
     *     MultipleFile::make('Files', 'files')
     *         ->rules('required')                 // the array must not be empty
     *         ->fileRules('mimes:jpg,png', 'max:10240'); // each file: type + size
     *
     * @param  array|string  $rules
     * @return $this
     */
    public function fileRules($rules)
    {
        $this->perFileRules = is_array($rules) ? $rules : func_get_args();

        return $this;
    }

    /**
     * Get the validation rules for this field, splitting per-file rules onto
     * "{attribute}.*" so they validate each uploaded file (a multiple-file
     * field always submits an array of files).
     *
     * @return array
     */
    public function getRules(NovaRequest $request): array
    {
        return $this->splitFileRules($this->resolveRawRules($this->rules, $request));
    }

    /**
     * Get the creation rules, keeping the per-file / array split intact.
     *
     * @return array
     */
    public function getCreationRules(NovaRequest $request): array
    {
        return array_merge_recursive(
            $this->getRules($request),
            $this->splitFileRules($this->resolveRawRules($this->creationRules, $request))
        );
    }

    /**
     * Get the update rules. On update, files already stored and kept by the
     * user satisfy presence rules (they are not re-sent as uploads), so
     * "required"/"present"/"filled" are relaxed when files are kept.
     *
     * @return array
     */
    public function getUpdateRules(NovaRequest $request): array
    {
        $rules = array_merge_recursive(
            $this->getRules($request),
            $this->splitFileRules($this->resolveRawRules($this->updateRules, $request))
        );

        if ($this->hasKeptFiles($request) && isset($rules[$this->attribute])) {
            $rules[$this->attribute] = array_values(array_filter(
                Arr::wrap($rules[$this->attribute]),
                fn ($rule) => ! in_array($rule, ['required', 'present', 'filled'], true)
            ));

            if (empty($rules[$this->attribute])) {
                unset($rules[$this->attribute]);
            }
        }

        return $rules;
    }

    /**
     * Normalise a rules definition (array or callable) into a flat array.
     *
     * @param  mixed  $rules
     * @return array
     */
    protected function resolveRawRules($rules, NovaRequest $request): array
    {
        $rules = is_callable($rules) ? call_user_func($rules, $request) : $rules;

        return Arr::wrap($rules);
    }

    /**
     * Split a set of rules between the array attribute ("files") and each file
     * within it ("files.*").
     *
     * @param  array  $rules
     * @return array
     */
    protected function splitFileRules(array $rules): array
    {
        $arrayRules = [];
        $perFile = $this->perFileRules;

        foreach ($rules as $rule) {
            if ($this->isSingleFileRule($rule)) {
                $perFile[] = $rule;
            } else {
                $arrayRules[] = $rule;
            }
        }

        $result = [];

        if (! empty($arrayRules)) {
            $result[$this->attribute] = array_values($arrayRules);
        }

        if (! empty($perFile)) {
            $result[$this->attribute . '.*'] = array_values($perFile);
        }

        return $result;
    }

    /**
     * Determine whether a rule operates on a single file (and thus belongs on
     * "{attribute}.*" rather than the array attribute).
     *
     * @param  mixed  $rule
     */
    protected function isSingleFileRule($rule): bool
    {
        if (! is_string($rule)) {
            return false;
        }

        $name = Str::lower(Str::before($rule, ':'));

        return in_array($name, $this->singleFileRuleNames, true);
    }

    /**
     * Determine whether the request keeps any already-stored files.
     */
    protected function hasKeptFiles(NovaRequest $request): bool
    {
        $keep = json_decode($request->input($this->attribute . '__keep', '[]'), true) ?: [];

        return ! empty($keep);
    }

    /**
     * Resolve the field's value for display, decorating each file with its URL.
     *
     * @param  mixed  $resource
     * @param  string|null  $attribute
     * @return void
     */
    public function resolve($resource, ?string $attribute = null): void
    {
        $attribute = $attribute ?? $this->attribute;

        $files = $this->decodeStoredValue(
            $this->resolveAttribute($resource, $attribute)
        );

        $this->value = collect($files)->map(function ($file) {
            return $this->decorateFile($file);
        })->values()->all();
    }

    /**
     * Resolve the field's value when used inside a Nova Action.
     *
     * Unlike the resource form (which stores files automatically), inside an action
     * the field exposes the raw uploaded files as an ARRAY so you can iterate them
     * and handle storage yourself:
     *
     *     public function handle(ActionFields $fields, Collection $models)
     *     {
     *         foreach ($fields->word_file as $file) {
     *             $path = $file->store('imports', 'public');
     *             // ... your own logic
     *         }
     *     }
     *
     * `$fields->word_file` is always an array of \Illuminate\Http\UploadedFile
     * (empty when nothing was uploaded), so the foreach is always safe.
     *
     * @param  object  $model
     * @return void
     */
    public function fillForAction(NovaRequest $request, object $model)
    {
        $model->{$this->attribute} = $this->uploadedFilesFromRequest($request, $this->attribute);
    }

    /**
     * Hydrate the given attribute on the model based on the incoming request.
     *
     * @param  string  $requestAttribute
     * @param  object  $model
     * @param  string  $attribute
     * @return \Closure|void
     */
    protected function fillAttributeFromRequest(NovaRequest $request, string $requestAttribute, object $model, string $attribute)
    {
        // Inside a Nova Action the field must expose the raw uploaded files as an
        // array (no automatic storage), exactly like fillForAction(). We handle it
        // here too because when the field lives inside a wrapper that forwards
        // fillInto() (e.g. a DependencyContainer), Nova routes through fill() and
        // never calls fillForAction() on us — which previously left the action's
        // value null and silently stored the files via the resource code path.
        if ($request->isActionRequest()) {
            $model->{$attribute} = $this->uploadedFilesFromRequest($request, $requestAttribute);

            return;
        }

        // Manual mode: a custom store() callback takes full control of persistence.
        if (is_callable($this->storeCallback)) {
            return $this->fillUsingCallback($request, $requestAttribute, $model, $attribute);
        }

        $existing = $this->decodeStoredValue($model->{$attribute} ?? null);

        // Files the front-end wants to keep (paths that were not removed by the user).
        $keepPaths = json_decode($request->input($requestAttribute . '__keep', '[]'), true) ?: [];
        $keepPaths = array_filter(array_map('strval', Arr::wrap($keepPaths)));

        // Delete the stored files that are no longer in the keep list.
        $kept = [];
        foreach ($existing as $file) {
            if (in_array($file['path'] ?? null, $keepPaths, true)) {
                $kept[] = $file;
            } else {
                $this->deleteFromDisk($file);
            }
        }

        // Store any newly uploaded files.
        $uploads = $this->uploadedFilesFromRequest($request, $requestAttribute);
        $new = [];
        foreach ($uploads as $upload) {
            if ($upload instanceof UploadedFile && $upload->isValid()) {
                $new[] = $this->storeFile($upload);
            }
        }

        $merged = array_values(array_merge($kept, $new));

        return function () use ($model, $attribute, $merged) {
            $model->{$attribute} = $this->encodeValueForModel($model, $attribute, $merged);
        };
    }

    /**
     * Hydrate the model using the user-provided custom store() callback.
     *
     * @param  string  $requestAttribute
     * @param  object  $model
     * @param  string  $attribute
     * @return \Closure|void
     */
    protected function fillUsingCallback(NovaRequest $request, $requestAttribute, $model, $attribute)
    {
        $result = call_user_func($this->storeCallback, $request, $model, $attribute, $requestAttribute);

        // The callback persisted everything itself.
        if ($result === true || $result === null) {
            return;
        }

        // A map of [column => value] pairs to assign on the model.
        if (is_array($result)) {
            return function () use ($model, $result) {
                foreach ($result as $key => $value) {
                    $model->{$key} = $value;
                }
            };
        }

        // A single scalar value assigned to this field's attribute.
        return function () use ($model, $attribute, $result) {
            $model->{$attribute} = $result;
        };
    }

    /**
     * Pull the uploaded files out of the request, supporting both array and single inputs.
     *
     * @param  string  $requestAttribute
     * @return array<int, \Illuminate\Http\UploadedFile>
     */
    protected function uploadedFilesFromRequest(NovaRequest $request, $requestAttribute)
    {
        if (! $request->hasFile($requestAttribute)) {
            return [];
        }

        $files = $request->file($requestAttribute);

        return is_array($files) ? array_values($files) : [$files];
    }

    /**
     * Persist a single uploaded file to the configured disk.
     *
     * @return array<string, mixed>
     */
    protected function storeFile(UploadedFile $file)
    {
        $directory = trim($this->storagePath, '/');
        $name = $this->generateFileName($file);

        $path = $file->storeAs($directory, $name, ['disk' => $this->disk]);

        return [
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'disk' => $this->disk,
            'size' => $file->getSize(),
            'mime' => $file->getClientMimeType(),
        ];
    }

    /**
     * Generate a unique, filesystem-safe file name preserving the extension.
     */
    protected function generateFileName(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $extension = $extension ? '.' . Str::lower($extension) : '';

        return Str::uuid()->toString() . $extension;
    }

    /**
     * Remove a stored file from its disk.
     *
     * @param  array<string, mixed>  $file
     */
    protected function deleteFromDisk(array $file): void
    {
        if (empty($file['path'])) {
            return;
        }

        $disk = $file['disk'] ?? $this->disk;

        if (Storage::disk($disk)->exists($file['path'])) {
            Storage::disk($disk)->delete($file['path']);
        }
    }

    /**
     * Decorate a stored file entry with a public/temporary URL for the front-end.
     *
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    protected function decorateFile(array $file): array
    {
        $disk = $file['disk'] ?? $this->disk;
        $path = $file['path'] ?? null;

        $url = null;
        if ($path) {
            try {
                $storage = Storage::disk($disk);
                $url = method_exists($storage, 'temporaryUrl') && $this->supportsTemporaryUrls($disk)
                    ? $storage->temporaryUrl($path, now()->addMinutes(5))
                    : $storage->url($path);
            } catch (\Throwable $e) {
                $url = null;
            }
        }

        return array_merge($file, [
            'disk' => $disk,
            'url' => $url,
            'isImage' => Str::startsWith($file['mime'] ?? '', 'image/'),
        ]);
    }

    /**
     * Determine whether a disk supports temporary (signed) URLs.
     */
    protected function supportsTemporaryUrls(string $disk): bool
    {
        $driver = config("filesystems.disks.{$disk}.driver");

        return in_array($driver, ['s3'], true);
    }

    /**
     * Normalise a stored attribute value into an array of file entries.
     *
     * @param  mixed  $value
     * @return array<int, array<string, mixed>>
     */
    protected function decodeStoredValue($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * Encode the merged file list for storage, honouring the model's cast (array vs string).
     *
     * @param  object  $model
     * @param  string  $attribute
     * @param  array<int, array<string, mixed>>  $files
     * @return array|string
     */
    protected function encodeValueForModel($model, $attribute, array $files)
    {
        if ($model instanceof Model && $this->modelCastsToArray($model, $attribute)) {
            return $files;
        }

        return json_encode($files);
    }

    /**
     * Determine whether the model casts the attribute to an array/json/collection.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     */
    protected function modelCastsToArray(Model $model, $attribute): bool
    {
        $cast = $model->getCasts()[$attribute] ?? null;

        return in_array($cast, ['array', 'json', 'object', 'collection'], true);
    }

    /**
     * Delete every stored file for the given model value (used by pruning helpers).
     *
     * @param  mixed  $value  The raw stored attribute value (json string or array).
     * @return void
     */
    public function purgeFiles($value): void
    {
        foreach ($this->decodeStoredValue($value) as $file) {
            $this->deleteFromDisk($file);
        }
    }

    /**
     * Prepare the field for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'maxFiles' => $this->maxFiles,
            'maxFileSize' => $this->maxFileSize,
            'acceptedTypes' => $this->acceptedTypes,
        ]);
    }
}
