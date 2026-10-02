import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Hide the development-only route indicator badge (compile/runtime errors are still shown).
  devIndicators: false,
};

export default nextConfig;
