"use client";

import { ImagePlus, X } from "lucide-react";
import { useRef, useState } from "react";
import { FormAlert } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { errorMessage } from "@/lib/form-errors";
import { carImageUrl, type Car } from "../api";
import { useDeleteCarImage, useUploadCarImage } from "../hooks";

const ACCEPT = "image/jpeg,image/png,image/webp";
const MAX_BYTES = 5 * 1024 * 1024;

export function CarImages({ car, canEdit }: { car: Car; canEdit: boolean }) {
  const input = useRef<HTMLInputElement>(null);
  const upload = useUploadCarImage(car.id);
  const remove = useDeleteCarImage(car.id);
  const [error, setError] = useState<string>();
  const images = car.images ?? [];

  const onFiles = async (files: FileList | null) => {
    setError(undefined);
    for (const file of Array.from(files ?? [])) {
      // Quick client-side checks; the API validates the real content.
      if (!ACCEPT.split(",").includes(file.type)) {
        setError(`${file.name}: only JPEG, PNG or WebP images are allowed.`);
        continue;
      }
      if (file.size > MAX_BYTES) {
        setError(`${file.name}: images must be 5 MB or smaller.`);
        continue;
      }
      try {
        await upload.mutateAsync(file);
      } catch (e) {
        setError(`${file.name}: ${errorMessage(e)}`);
      }
    }
    if (input.current) input.current.value = "";
  };

  const onDelete = (imageId: string, name: string) => {
    if (!window.confirm(`Delete image "${name}"?`)) return;
    setError(undefined);
    remove.mutate(imageId, { onError: (e) => setError(errorMessage(e)) });
  };

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle>Images</CardTitle>
          <CardDescription>JPEG, PNG or WebP, up to 5 MB each, at most 20 per car.</CardDescription>
        </div>
        {canEdit ? (
          <>
            <input ref={input} type="file" accept={ACCEPT} multiple className="sr-only" id="car-image-input" onChange={(e) => onFiles(e.target.files)} />
            <Button variant="outline" size="sm" disabled={upload.isPending} onClick={() => input.current?.click()}>
              <ImagePlus aria-hidden />
              {upload.isPending ? "Uploading…" : "Add images"}
            </Button>
          </>
        ) : null}
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <FormAlert message={error} />
        {images.length === 0 ? (
          <p className="text-sm text-muted-foreground">No images yet.</p>
        ) : (
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            {images.map((image) => (
              <li key={image.id} className="group relative overflow-hidden rounded-lg border bg-muted">
                {/* eslint-disable-next-line @next/next/no-img-element -- authenticated API file, not optimizable */}
                <img src={carImageUrl(image)} alt={image.original_name} loading="lazy" className="aspect-[4/3] w-full object-cover" />
                {canEdit ? (
                  <Button
                    variant="secondary"
                    size="icon-xs"
                    aria-label={`Delete image ${image.original_name}`}
                    className="absolute right-1.5 top-1.5"
                    disabled={remove.isPending}
                    onClick={() => onDelete(image.id, image.original_name)}
                  >
                    <X aria-hidden />
                  </Button>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
