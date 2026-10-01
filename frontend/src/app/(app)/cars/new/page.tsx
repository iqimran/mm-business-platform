"use client";

import { useRouter } from "next/navigation";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { usePermissions } from "@/features/auth/hooks";
import { CarForm } from "@/features/cars/components/car-form";
import { useCreateCar } from "@/features/cars/hooks";

export default function NewCarPage() {
  const router = useRouter();
  const { can } = usePermissions();
  const createCar = useCreateCar();

  if (!can("car.create")) return <Forbidden />;

  return (
    <>
      <PageHeader title="New car" description="Register a car in one of your branches. Purchase details are recorded separately." />
      <CarForm
        submitLabel="Create car"
        onCancel={() => router.push("/cars")}
        onSubmit={async (input) => {
          const car = await createCar.mutateAsync(input);
          router.push(`/cars/${car.id}`);
        }}
      />
    </>
  );
}
