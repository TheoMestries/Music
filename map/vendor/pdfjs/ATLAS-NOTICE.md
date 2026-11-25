PDF.js / pdfjs-dist 6.5.438, Mozilla contributors.

Source: https://registry.npmjs.org/pdfjs-dist/-/pdfjs-dist-6.5.438.tgz
Documentation: https://mozilla.github.io/pdf.js/examples/
License: Apache-2.0 (see LICENSE and the font/wasm license files).

Vendored build/pdf.js, build/pdf.worker.js, cmaps, standard_fonts and wasm.
The two upstream .mjs files use .js filenames so hosts serve them with a
JavaScript MIME type. They are loaded as ES modules.
No CDN is required at runtime. Used only for rasterizing PDF sheet templates
in the browser; sheet values and field definitions are managed by Atlas.
