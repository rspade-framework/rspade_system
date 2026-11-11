# ssr

The SSR Node server's lifecycle, `Rsx_SSR` (`app/RSpade/Core/SSR/Rsx_SSR.php`): a daemon
(`bin/ssr-server.js --socket=<tmp>/ssr-server.sock`) started on demand, recorded in
`<tmp>/ssr-server.pid`, restarted when the build key changes.

## Behavior of record

- Stopping the server signals the pid in `ssr-server.pid` only while that pid is still THIS
  project's server: its command line names `ssr-server.js` and `--socket=<this project's
  socket>`. The pidfile outlives the server (a crash, a container restart) and the kernel
  reuses pids, so any other process at that pid is left alone; the stale pidfile is removed
  either way.

Rendering through the server is observed over HTTP in `tests/fpc` (`Fpc_Ssr_Disabled_Test`).
