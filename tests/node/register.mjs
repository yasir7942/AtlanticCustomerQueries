// node --import ./tests/node/register.mjs ... : resolve "server-only" to a stub and extensionless .ts imports.
import { register } from 'node:module';
register('./loader.mjs', import.meta.url);
