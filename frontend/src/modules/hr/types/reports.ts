export interface ReportSummaryItem {
  label: string;
  value: string;
}

export interface ReportBarItem {
  label: string;
  value: number;
  display: string;
}

export interface ReportBars {
  title: string;
  items: ReportBarItem[];
}

export interface ReportTable {
  columns: string[];
  rows: string[][];
}

export interface TitledReportTable extends ReportTable {
  title: string;
}

export interface ReportExportEntry {
  when: string | null;
  by: string;
}

export interface MovementPoint {
  label: string;
  joiners: number;
  leavers: number;
  net: number;
}

export interface ReportDepartment {
  id: number;
  name: string;
}

export interface Report {
  title: string;
  description: string;
  mock: boolean;
  restricted?: boolean;
  summary: ReportSummaryItem[];
  bars: ReportBars | null;
  table: ReportTable | null;
  // Payroll & Cost specific — government remittance (employee vs employer).
  remittance?: TitledReportTable | null;
  note: string | null;
  exports: ReportExportEntry[];
  // Headcount & Movement specifics.
  movement?: MovementPoint[];
  departments?: ReportDepartment[];
  // Recruitment Funnel specifics.
  funnel?: ReportBarItem[];
  // Absences & Leaves + Compensation specifics.
  stacked?: StackedSeries;
  // Attrition & Tenure specifics.
  departures?: StackedSeries;
  tenure?: ReportBarItem[] | { label: string; value: number }[];
  // Diversity specifics.
  representation?: StackedSeries;
  // Audit Trail specifics.
  entries?: AuditEntry[];
}

export interface AuditEntry {
  timestamp: string;
  user: string;
  module: string;
  action: string;
  details: string;
}

export interface StackedSeriesDef {
  name: string;
  color: string | null;
}

export interface StackedPoint {
  label: string;
  values: number[];
}

export interface StackedSeries {
  series: StackedSeriesDef[];
  points: StackedPoint[];
}
