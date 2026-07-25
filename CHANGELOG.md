# Changelog

## 1.1.0 - 2026-07-25

- Reject traversal, absolute paths, unsafe path segments, and local-storage
  symlink escapes.
- Calculate upload size from the temporary file instead of client metadata.
- Block common executable/script extensions by default.
- Prevent fixed filenames from changing the validated extension.
- Restrict generated image formats and reliably clean temporary files.
- Add focused security tests.
