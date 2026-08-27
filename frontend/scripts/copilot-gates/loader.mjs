// Module loader for the Copilot regression gates.
//
// The runner drives the REAL matcher and response engine straight from source rather than
// reimplementing them, so a gate can never pass against a copy that has drifted. Node 24 strips the
// TypeScript types natively; only two things still need bridging, and this hook does both:
//
//   1. the "@/" path alias, which is a bundler feature Node knows nothing about, and
//   2. bundler-style JSON imports — the source omits `with { type: "json" }`, so those specifiers are
//      re-served as ES modules here.
//
// A third rule resolves bare package specifiers (fuse.js, minisearch) from frontend/ rather than from
// this directory, so the gates use the same installed copies the app does.
//
// .mjs, like postcss.config.mjs and eslint.config.mjs: a Node loader runs outside the app's module
// graph and cannot be part of the TypeScript build.
import { readFileSync, existsSync } from "node:fs";
import { fileURLToPath, pathToFileURL } from "node:url";

const SRC = new URL("../../src/", import.meta.url);
const SRC_PATH = fileURLToPath(SRC);

function withExtension(path) {
  if (existsSync(path) && !path.endsWith("/")) return path;
  for (const ext of [".ts", ".tsx", ".json", "/index.ts", "/index.tsx"]) {
    if (existsSync(path + ext)) return path + ext;
  }
  return path;
}

export async function resolve(specifier, context, next) {
  if (specifier.startsWith("@/")) {
    return { url: pathToFileURL(withExtension(SRC_PATH + specifier.slice(2))).href, shortCircuit: true };
  }

  if (specifier.startsWith(".") && context.parentURL?.startsWith("file:")) {
    const resolved = withExtension(fileURLToPath(new URL(specifier, context.parentURL)));
    if (existsSync(resolved)) return { url: pathToFileURL(resolved).href, shortCircuit: true };
  }

  // Bare package specifiers resolve from frontend/, not from scripts/.
  if (!specifier.startsWith(".") && !specifier.startsWith("/") && !specifier.startsWith("node:")) {
    return next(specifier, { ...context, parentURL: new URL("anchor.ts", SRC).href });
  }

  return next(specifier, context);
}

export async function load(url, context, next) {
  if (url.endsWith(".json")) {
    return {
      format: "module",
      source: `export default ${readFileSync(fileURLToPath(url), "utf8")};`,
      shortCircuit: true,
    };
  }
  return next(url, context);
}
