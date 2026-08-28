// Registers loader.mjs, so the npm script stays a readable one-liner.
import { register } from "node:module";
register("./loader.mjs", import.meta.url);
