export interface Department {
  id: number;
  name: string;
  code: string;
  is_active: boolean;
  created_at: string;
}

/** Payload for creating/updating a department. */
export interface DepartmentInput {
  name: string;
  code: string;
  is_active: boolean;
}
