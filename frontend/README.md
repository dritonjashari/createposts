# CreatePosts — frontend

React + TypeScript + Vite single-page app for CreatePosts (the PHP API is in `../backend`).

```
npm run dev     # http://localhost:5173, API at the URL in .env.development
npm run build   # type-check + production build into dist/
```

On a server, run `npm run build` in the repository root instead. It installs the dependencies and builds `dist/`, which `../index.php` serves.
The build uses relative URLs, and `index.php` injects the real path as `<base href>`, so the same build works at a domain root or in any subfolder.
See the [README in the repository root](../README.md).
