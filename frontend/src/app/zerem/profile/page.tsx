import { SignedInDevices } from "@/core/components/signed-in-devices";
import { BdSettingsPage } from "@/modules/crm/features/profile/components/bd-settings-page";

export default function Profile() {
  return (
    <div className="flex flex-col gap-6">
      <BdSettingsPage />
      <SignedInDevices />
    </div>
  );
}
