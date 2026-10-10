/**
 * Resumable chunked upload client for examples/chunked-upload.php.
 *
 *   const result = await uploadInChunks(fileInput.files[0], '/upload-endpoint', {
 *       onProgress: (sent, total) => console.log(Math.round(sent / total * 100) + '%'),
 *   });
 *
 * Failed chunks are retried; after a network error the client asks the
 * server how many bytes it has (action=status) and continues from there.
 * To resume after a page reload, pass the stored upload id as options.uploadId.
 */
async function uploadInChunks(file, endpoint, options = {}) {
    const retries = options.retries ?? 5;
    const headers = options.headers ?? {};
    const csrf = document.querySelector('meta[name="csrf-token"]');
    if (csrf && !headers['X-CSRF-TOKEN']) {
        headers['X-CSRF-TOKEN'] = csrf.getAttribute('content');
    }

    async function call(fields, chunk) {
        const body = new FormData();
        for (const [key, value] of Object.entries(fields)) {
            body.append(key, value);
        }
        if (chunk) {
            body.append('chunk', chunk, 'chunk');
        }

        const response = await fetch(endpoint, { method: 'POST', body, headers, credentials: 'same-origin' });
        const data = await response.json();
        if (response.status === 409) {
            return { offsetMismatch: true, ...data };
        }
        if (!response.ok) {
            const error = new Error(data.error || 'Upload failed');
            error.fatal = response.status === 422;
            throw error;
        }
        return data;
    }

    let session = options.uploadId
        ? await call({ action: 'status', upload_id: options.uploadId })
        : await call({ action: 'start', filename: file.name, size: file.size });
    options.onStart?.(session.upload_id);

    const uploadId = session.upload_id;
    const chunkSize = session.chunk_size;
    let offset = session.received_bytes;
    let failures = 0;

    while (offset < file.size) {
        try {
            const chunk = file.slice(offset, offset + chunkSize);
            const status = await call({ action: 'append', upload_id: uploadId, offset }, chunk);
            offset = status.received_bytes;
            failures = 0;
            options.onProgress?.(offset, file.size);
        } catch (error) {
            if (error.fatal || ++failures > retries) {
                throw error;
            }
            await new Promise((resolve) => setTimeout(resolve, 1000 * failures));
            offset = (await call({ action: 'status', upload_id: uploadId })).received_bytes;
        }
    }

    return call({ action: 'complete', upload_id: uploadId });
}
