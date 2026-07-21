export interface DepartmentPosition {
  id: number;
  name: string;
}

export interface DepartmentHeadRef {
  id: number;
  name: string;
}

/** An employee currently assigned to a role in a department. */
export interface DepartmentAssignment {
  employee_id: number;
  name: string | null;
  role: string;
}

export interface Department {
  id: number;
  name: string;
  code: string;
  description: string | null;
  is_active: boolean;
  head_id: number | null;
  head: DepartmentHeadRef | null;
  /** Roles/positions that exist within this department. */
  positions: DepartmentPosition[];
  /** Employees currently holding a role in this department. */
  assignments: DepartmentAssignment[];
  created_at: string;
}

/** Payload for creating/updating a department. */
export interface DepartmentInput {
  name: string;
  code: string;
  description?: string | null;
  is_active: boolean;
  head_id?: number | null;
  /** Position/role names within the department. */
  positions?: string[];
  /** Employee→role assignments (assigning moves the employee into this dept). */
  assignments?: { employee_id: number; role: string }[];
}
