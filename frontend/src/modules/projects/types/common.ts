// NOTE: project-management entities (workspace, project, task, subtask, status,
// group, comment, link, activity log) use UUID string ids. User ids remain
// numeric (assignee_id, leader_id, author, user), so those stay `number`.
export type Priority = "Low" | "Medium" | "High";
export type AssignmentType = "group" | "individual";
export type StatusKey = "todo" | "in_progress" | "done" | "custom";
export type FlagReason = "assignee_left_group" | "group_reassigned";

/**
 * Review state of a task/sub-task. Anything a PM/Admin creates is "approved" on
 * the spot; a member's proposal starts "pending" and stays off every board,
 * workload total and search result until it is approved. Only its author (and
 * the review queue) can see it in the meantime.
 */
export type ApprovalStatus = "approved" | "pending" | "rejected";
