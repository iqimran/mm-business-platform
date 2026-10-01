import { redirect } from "next/navigation";

// The dashboard sends unauthenticated visitors to /login.
export default function Home() {
  redirect("/dashboard");
}
