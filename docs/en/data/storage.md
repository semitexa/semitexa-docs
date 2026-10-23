---
id: data/storage
section: data
slug: storage
title: File Storage and Uploads
summary: Keep files with StorageObjectStoreInterface on local disk or an S3-compatible store, receive multipart uploads as UploadedFile, and size the server for them.
order: 120
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - StorageObjectStoreInterface
  - StorageDriverInterface
  - StorageManager
  - UploadedFile
  - getFiles()
  - STORAGE_DRIVER
  - SWOOLE_PACKAGE_MAX_LENGTH
  - storage:migrate-metadata
---

# File Storage and Uploads

`semitexa/storage` gives one file API over two backends: a directory on the local disk and an S3-compatible object store. It is installed in every project. A handler writes `put('invoices/2026/42.pdf', $bytes, 'application/pdf')` and does not care where the bytes go. `STORAGE_DRIVER` decides that.

Uploads arrive through `semitexa/core` as `UploadedFile` objects, either on the `Request` or through a payload setter. Storing one is your code's job: read its bytes and `put()` them. If the bytes are images that need resized variants, use `semitexa/media`, which builds on this package.

## Getting it

Inject the interface with `#[InjectAsReadonly]`. Both contracts resolve to `StorageManager`, the worker's one shared instance, which hands every call to the configured driver.

```php
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Storage\Contract\StorageObjectStoreInterface;

#[AsService]
final class InvoiceArchive
{
    #[InjectAsReadonly]
    protected StorageObjectStoreInterface $storage;

    public function keep(int $invoiceId, string $pdf): string
    {
        $key = sprintf('invoices/%d.pdf', $invoiceId);
        $this->storage->put($key, $pdf, 'application/pdf');

        return $key;   // store the key, not the URL
    }
}
```

The whole contract. `StorageDriverInterface` has the first five methods, and `StorageObjectStoreInterface` extends it with the last three:

```php
put(string $path, string $contents, string $mimeType): void
get(string $path): ?string                      // null: no such object
delete(string $path): bool                      // see "Drivers differ" below
exists(string $path): bool
url(string $path): string                       // '' when the object has no public address
stat(string $path): ?StoredObjectMetadata
readStream(string $path)                        // resource|null; you close it
describe(string $path): ?StoredObjectDescriptor
```

The API has no listing, copy, move, append or signed-URL method. `put()` takes the whole object as a string.

## Choosing the driver

| Env key | Default | Meaning |
|---|---|---|
| `STORAGE_DRIVER` | `local` | `local` or `s3`, case and spaces ignored. Any other value is refused when storage is first used, rather than quietly writing to the local disk |
| `STORAGE_LOCAL_PUBLIC_URL` | empty | Base URL under which you serve the local storage root. Empty means local objects have no URL |
| `STORAGE_S3_BUCKET` | empty | Bucket name |
| `STORAGE_S3_REGION` | `us-east-1` | Signing region |
| `STORAGE_S3_ENDPOINT` | `https://s3.<region>.amazonaws.com` | Scheme, host and optional port of the store |
| `STORAGE_S3_KEY` / `STORAGE_S3_SECRET` | empty | Access key pair |
| `STORAGE_S3_PATH_STYLE` | by endpoint | `1`, `true`, `yes` or `on` puts the bucket in the path (`<endpoint>/<bucket>/<key>`), any other value in the host name; unset, an IP address, `localhost`, a one-word host (a compose service such as `objects`) or a bucket name with a dot is path style, any other virtual-hosted |
| `STORAGE_S3_TRANSFER_TIMEOUT` | `600` | seconds an upload or a download may take in all; an upload gets more as its body is larger (at 128 KiB/s) |
| `STORAGE_S3_MISSING_IS_FORBIDDEN` | off | `1`, `true`, `yes` or `on` makes a 403 on a read count as "not found" (see below) |

`StorageManager` creates the driver on first use and keeps it for the worker's lifetime. After changing `STORAGE_DRIVER`, restart the server.

### Local

Objects live under `var/uploads/` in the project root. The root is `PROJECT_ROOT` when it is set, and otherwise the project the code runs in (found from the working directory upwards), so a script started from a subdirectory writes to the same place. No env key moves the root. `var/uploads/*` is git-ignored in a new project.

`url()` returns `STORAGE_LOCAL_PUBLIC_URL` with the key appended, or `''` when the variable is empty. The framework does not serve `var/uploads`. Set the variable only if something you run (a static mount, a CDN, a route of your own) actually serves that directory at that URL. Otherwise leave it empty and serve the bytes from a handler.

With more than one node, `local` only works if `var/uploads` is a volume that every node shares.

### S3-compatible

Requests are signed with Signature Version 4 and sent **virtual-hosted style**, `<scheme>://<bucket>.<endpoint host>[:port]/<key>`, or **path style**, `<endpoint>/<bucket>/<key>`, as `STORAGE_S3_PATH_STYLE` and the endpoint decide (see the table). An upload or a download (`PUT`, `GET`) may take `STORAGE_S3_TRANSFER_TIMEOUT` seconds for the whole transfer (600 by default; an upload more as its body is larger, at 128 KiB/s); any other request (`HEAD`, `DELETE`) has 30 seconds. The limit is on the whole transfer, not on a pause in it: a store that stops sending mid-transfer holds the call that long, twice for a read, which is retried. An idempotent one (a read, a delete) is tried twice in all. Each path segment of the key is percent-encoded, so spaces and non-ASCII characters work. A key with a `.` or `..` segment is refused with a `StorageException`: one tool folds it away before sending and another does not, so the signed path and the sent one could differ. An empty MIME type is sent as `application/octet-stream`.

`url()` builds a **path-style**, unsigned address instead, `<endpoint>/<bucket>/<key>`. It opens only for objects the bucket lets anyone read.

An object-level-only bucket policy (no `s3:ListBucket`) answers **403** for a missing key. By default the driver treats any 403 as a failure and throws. On such a bucket, set `STORAGE_S3_MISSING_IS_FORBIDDEN=1` so that `get()`, `exists()`, `stat()`, `readStream()` and `describe()` read a 403 as "not there". A 403 on `put()` or `delete()` always throws.

## Keys

A key is a `/`-separated relative path. Choose keys on the server: an id you generated, plus an extension taken from the type you detected. Never use the client's file name as a key.

- **Local confinement.** The driver resolves `.` and `..` segments in the text of the key, then checks the result against the real path of the root, following symlinks inside it. Any key that would land outside the root throws `StorageException` on every method that touches the disk, reads included; `url()` only appends the key to `STORAGE_LOCAL_PUBLIC_URL`. So does a key that points into `.meta/`, the driver's own metadata directory. A write through a dangling symlink is refused. Because the key is normalised, `a//b/../c.txt` and `a/c.txt` name the same object.
- **S3** does nothing to the key except remove a leading `/`. It has no notion of the local root and needs none.
- **Tenants.** The package does not add a tenant prefix to keys. If tenants must not see each other's files, put the tenant id into the key yourself. `semitexa/media` does that for its own objects.

## Metadata

`stat()` returns a `StoredObjectMetadata` (`path`, `exists`, `size`, `mimeType`, `lastModifiedAt`, `etag`, `checksum`). `describe()` returns a `StoredObjectDescriptor` (`driver`, `path`, `url`, `size`, `mimeType`, `etag`), which is safe to serialise. Both return `null` when the object does not exist.

| Field | local | s3 |
|---|---|---|
| `size` | file size | `Content-Length` |
| `mimeType` | the type passed to `put()`; detected from the bytes if none was recorded | `Content-Type` as stored |
| `lastModifiedAt` | file mtime | `Last-Modified` |
| `etag` | `null` | `ETag`, quotes stripped |
| `checksum` | `null` | `null` |

The local driver writes the MIME type to `.meta/<key>.json`. Objects written by older versions kept it next to the object as `<key>.meta.json`. The driver still reads those files but never writes or deletes them. To copy them into `.meta/`:

```bash
bin/semitexa storage:migrate-metadata                  # dry run: prints the plan
bin/semitexa storage:migrate-metadata --apply          # copies into .meta/
bin/semitexa storage:migrate-metadata --apply --remove-legacy
bin/semitexa storage:migrate-metadata --path=/srv/other-root
```

A legacy file is only treated as metadata if it holds exactly `{"mimeType": "<string>"}` and the object it describes exists. Anything else is listed as skipped and left where it is.

## Failures

A failed write never looks like success, and an outage never looks like a missing file.

| Situation | Result |
|---|---|
| Object does not exist | `get`/`stat`/`describe`/`readStream` → `null`, `exists` → `false` |
| Local write fails (disk full, permissions, directory missing) | `StorageException` |
| Local key escapes the root or points into `.meta/` | `StorageException` |
| Two local keys whose metadata paths collide (`report` and `report.json/x`) | the second `put()` throws before anything is written |
| S3 answers outside 2xx on `put()` | `StorageException` |
| S3 answers anything but 2xx or 404 on a read or delete | `StorageException` with the method and status, never the response body (a 403 on a read can be configured to mean missing) |
| DNS, TLS, connection or timeout failure | `StorageException` |
| `STORAGE_DRIVER` names no driver | `StorageException` on every call; `bin/semitexa system:doctor` reports it (`storage.driver`) |

`StorageException` is `Semitexa\Storage\Exception\StorageException`, a `RuntimeException`.

## Receiving uploads

Swoole parses `multipart/form-data`, and `RequestFactory` turns every file part into a `Semitexa\Core\Http\UploadedFile`:

```php
public string $field;
public string $clientFilename;    // as the browser sent it; untrusted
public string $clientMimeType;    // as the browser sent it; untrusted
public int    $size;
public string $tmpPath;
public int    $errorCode;         // UPLOAD_ERR_*

isOk(): bool                      // UPLOAD_ERR_OK and a temp path
hasError(): bool
errorMessage(): string
getContents(): string             // '' when not OK or the temp file is gone
sha256(): string
UploadedFile::isSafeClientFilename(string $name): bool
```

From the request:

```php
$request->hasFile('document');    // the field was sent, even as an empty file input
$request->getFile('document');    // ?UploadedFile, the first file of the field
$request->getFiles('attachments');// list<UploadedFile>; one file becomes a one-element list
```

### Into a payload

The payload hydrator passes files to setters the same way it passes form fields: the field `document` goes to `setDocument()`. A file wins over a text field with the same name. The setters run first, then `setHttpRequest()`, then `validate()`, so `validate()` can check the file.

```php
use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Http\UploadedFile;

#[AsProtectedPayload(
    path: '/account/documents',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['multipart/form-data'],
)]
final class DocumentUploadPayload implements ValidatablePayloadInterface
{
    private ?UploadedFile $document = null;

    /** @var list<UploadedFile> */
    private array $attachments = [];

    public function setDocument(?UploadedFile $document): void
    {
        $this->document = $document;
    }

    /** `<input type="file" name="attachments[]" multiple>`: type the setter `array`. */
    public function setAttachments(array $attachments): void
    {
        $this->attachments = array_values(array_filter(
            $attachments,
            static fn (mixed $f): bool => $f instanceof UploadedFile && $f->isOk(),
        ));
    }

    public function document(): ?UploadedFile
    {
        return $this->document;
    }

    public function validate(): array
    {
        if ($this->document === null || !$this->document->isOk()) {
            return ['document' => [$this->document?->errorMessage() ?? 'No file was sent.']];
        }
        if ($this->document->size > 5 * 1024 * 1024) {
            return ['document' => ['The file is larger than 5 MB.']];
        }

        return [];
    }
}
```

Errors from `validate()` come back in the standard 422 envelope. In a signed-in browser the form also needs `{{ csrf_field() }}`; see [CSRF](../auth/csrf.md).

The handler checks what the bytes actually are, picks the key, and stores the file:

```php
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Storage\Contract\StorageObjectStoreInterface;

#[AsPayloadHandler(payload: DocumentUploadPayload::class, resource: ResourceResponse::class)]
final class DocumentUploadHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected StorageObjectStoreInterface $storage;

    public function handle(DocumentUploadPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $file = $payload->document();
        if ($file === null || !$file->isOk()) {
            return $resource->setStatusCode(HttpStatus::UnprocessableEntity->value);
        }

        // What the bytes are, not what the browser claimed.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->tmpPath) ?: 'application/octet-stream';
        if ($mime !== 'application/pdf') {
            return $resource->setStatusCode(HttpStatus::UnprocessableEntity->value);
        }

        $key = 'documents/' . bin2hex(random_bytes(16)) . '.pdf';
        $this->storage->put($key, $file->getContents(), $mime);

        return $resource
            ->setStatusCode(HttpStatus::Created->value)
            ->setHeader('Content-Type', 'application/json')
            ->setContent((string) json_encode(['key' => $key]));
    }
}
```

### From a platform UI form

A `platform.field` with `control: 'file'` uploads the file as soon as it is chosen. The form submits a signed one-time ticket in its place, and the form action redeems it with `UiUploadTickets::fromForm()`, which returns a `UiUploadedFile`. The file's real type and size were checked at upload, against the limits signed into the field. See [UI Forms → Uploads](../rendering/ui-forms.md). To keep the file in storage rather than in a local directory:

```php
use Semitexa\PlatformUi\Application\Service\Upload\UiUploadTickets;

$file = UiUploadTickets::fromForm($context, 'avatar');
if ($file !== null) {
    $key = 'avatars/' . $file->id . '.' . $file->extension();
    $this->storage->put($key, $file->contents(), $file->mimeType);
    @unlink($file->path);   // otherwise it stays in var/tmp/ui-uploads until a sweep
}
```

`mimeType` was read from the bytes at upload time, and `extension()` is derived from it. `clientName` is for display only.

## Size limits

One upload is one request, and Swoole refuses a request larger than its `package_max_length` before any handler runs. The visitor's connection fails, and nothing reaches the application log. Set the limit with `SWOOLE_PACKAGE_MAX_LENGTH`, in bytes. The default is 33554432 (32 MiB). Values below 65536 stop the server from booting. Restart the server after changing it.

Every other limit sits below this one. A plain multipart route has no size limit of its own: check `$file->size` in `validate()`, as above. A platform UI file field has `maxBytes`, 1 MiB by default, and fails to render if `maxBytes` is above the server limit minus 16 KiB. The details are in [UI Forms → Size limits](../rendering/ui-forms.md).

## Watch out for

- **Path style is chosen by the endpoint unless you say.** `http://objects:9000` and `http://127.0.0.1:9000` are path style without a setting; a self-hosted store behind a dotted name (`https://objects.example.com`, a cluster-internal `*.svc.cluster.local`) is sent virtual-hosted requests unless `STORAGE_S3_PATH_STYLE=1`. A bucket name with a dot is path style unless `STORAGE_S3_PATH_STYLE=0`: as a host name (`my.bucket.s3.…`) it fails the certificate check.
- **`url()` is not a download link.** Local returns `''` unless `STORAGE_LOCAL_PUBLIC_URL` is set. S3 returns an unsigned path-style URL that works only for publicly readable objects. Store keys, and build URLs when you render.
- **`delete()` on S3 costs two requests.** S3 answers 204 whether or not the key existed, so the driver asks first (HEAD) and returns `false` for a missing object, as the local driver does. Two deletes racing may both report `true`. With `STORAGE_S3_MISSING_IS_FORBIDDEN=1`, the HEAD of a missing key answers 403 like that of a key that is there, so the driver sends the DELETE anyway, and `delete()` returns `true` for a key that was not there. On such a bucket, `true` means the key is gone, not that this call removed it.
- **`readStream()` on S3 downloads first.** The object is written into a `php://temp` stream as it arrives (in memory up to 2 MiB, then a temporary file) and the stream is handed back once the download is complete, so a large object costs disk, not memory (inside the application server, up to twice its size while it arrives), and `STORAGE_S3_TRANSFER_TIMEOUT` covers the whole download. A download that arrives shorter than the store said it is throws rather than returning a cut stream. Only the local driver returns a stream over the file itself.
- **A key is a file, not a folder.** On the local driver a key that names a directory (`invoices` when `invoices/2026.pdf` exists) is no object: `exists()` is `false`, `get()`, `stat()` and `readStream()` return `null`, `delete()` returns `false`.
- **The stored MIME type is what you passed.** The local driver records whatever `put()` received and returns it from `stat()` without checking. Detect the type from the bytes before you call `put()`. Never pass `$file->clientMimeType`.
- **An empty file input is still a file.** Swoole delivers it with `UPLOAD_ERR_NO_FILE` and an empty temp path. `hasFile()` is `true` and a `?UploadedFile` setter receives an object, not `null`. Check `isOk()`.
- **A wrong shape in a file setter.** Two files sent under a field whose setter is typed `UploadedFile`, or a text value under that field's name, are refused with a 422 that names the field: "expected one uploaded file". For a field that may carry several files, type the setter `array`: a single file is then wrapped into a one-element list.
- **Nested file field names keep their brackets.** `name="doc[avatar]"` arrives as `$request->getFiles('doc[avatar]')`, and `doc[photos][]` as a list under `doc[photos]`. A payload setter receives a file only under a flat name, so use flat names (or `name[]` for lists) for files a payload should take.
- **The temp file dies with the request.** Swoole deletes `tmpPath` once the response is sent. Read or store the bytes inside the request. Never pass `tmpPath` to a queued handler or to anything that runs after the response.
- **`getContents()` reads the whole file into memory**, and `put()` takes a string. With the default limit, a single upload can be up to 32 MiB of memory in one worker.
- **Concurrent writers.** Drivers keep no mutable state and are safe across coroutines. Two processes writing the same key race, and the last write wins. Two processes writing colliding local keys (`report` and `report.json/x`) at the same moment can both persist their objects, while one of them still reports the collision.
