import { apiClient } from "@/core/api-client";
import type { CompanyProfile, CompanyProfileInput } from "@/modules/hr/types/company";

export async function getCompanyProfile(): Promise<CompanyProfile> {
  const res = await apiClient.get("/hr/company-profile");
  return res.data.data;
}

export async function updateCompanyProfile(
  input: CompanyProfileInput,
): Promise<CompanyProfile> {
  const res = await apiClient.put("/hr/company-profile", input);
  return res.data.data;
}

export async function uploadCompanyLogo(logo: File): Promise<CompanyProfile> {
  const form = new FormData();
  form.append("logo", logo);
  const res = await apiClient.post("/hr/company-profile/logo", form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return res.data.data;
}

export async function deleteCompanyLogo(): Promise<CompanyProfile> {
  const res = await apiClient.delete("/hr/company-profile/logo");
  return res.data.data;
}
