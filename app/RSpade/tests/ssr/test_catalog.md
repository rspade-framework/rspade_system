# ssr - test catalog

## Rsx_Ssr_Stop_Server_Test (php, no-tx) - the pidfile names the server, or nothing is signalled

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| ssr-01 | an unrelated live process at the recorded pid survives a stop | `sleep` pid in a scratch tmp tree's ssr-server.pid | still running; pidfile removed | implemented |
| ssr-02 | another project's server at the recorded pid survives a stop | node naming ssr-server.js with another `--socket=` | still running; pidfile removed | implemented |
| ssr-03 | this project's server is stopped | node naming ssr-server.js with this tree's `--socket=` | SIGTERMed; pidfile removed | implemented |
