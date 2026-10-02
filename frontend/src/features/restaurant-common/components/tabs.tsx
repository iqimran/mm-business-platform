"use client";

/** Simple accessible tab bar (content is rendered by the caller for the selected key). */
export function Tabs<K extends string>({ label, tabs, value, onChange }: { label: string; tabs: [K, string][]; value: K; onChange: (key: K) => void }) {
  return (
    <div role="tablist" aria-label={label} className="flex gap-1 overflow-x-auto border-b">
      {tabs.map(([key, text]) => (
        <button
          key={key}
          role="tab"
          type="button"
          aria-selected={value === key}
          className={`-mb-px shrink-0 border-b-2 px-3 py-2 text-sm ${value === key ? "border-primary font-medium" : "border-transparent text-muted-foreground hover:text-foreground"}`}
          onClick={() => onChange(key)}
        >
          {text}
        </button>
      ))}
    </div>
  );
}
