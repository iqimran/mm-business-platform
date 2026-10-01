import { fileURLToPath } from "node:url";
import { defineConfig } from "vitest/config";

// Unit tests for pure frontend logic (schemas, formatting, payload mapping). The API stays authoritative.
export default defineConfig({
  resolve: { alias: { "@": fileURLToPath(new URL("./src", import.meta.url)) } },
  test: { environment: "node", include: ["src/**/*.test.ts"] },
});
