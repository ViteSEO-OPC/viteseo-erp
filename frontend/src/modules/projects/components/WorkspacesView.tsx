'use client';

import Link from 'next/link';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  Button,
  Card,
  ConfirmDialog,
  Field,
  Input,
  Modal,
  PencilIcon,
  PlusIcon,
  SearchIcon,
  Textarea,
  TrashIcon,
} from '@/core/ui';
import { useIsManager } from '@/core/auth/AuthProvider';
import { useCreateWorkspace, useDeleteWorkspace, useWorkspaces } from '../hooks';
import type { Workspace } from '../types';
import { EditWorkspaceModal } from './EditWorkspaceModal';

const schema = z.object({
  name: z.string().min(1, 'Name is required').max(255),
  description: z.string().max(2000).optional(),
});
type FormData = z.infer<typeof schema>;

function CreateWorkspaceModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const create = useCreateWorkspace();
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormData>({ resolver: zodResolver(schema) });

  const submit = handleSubmit(async (data) => {
    await create.mutateAsync({ name: data.name, description: data.description || undefined });
    reset();
    onClose();
  });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Create Workspace"
      footer={
        <>
          <Button variant="outline" onClick={onClose} type="button">
            Cancel
          </Button>
          <Button onClick={submit} disabled={create.isPending} type="button">
            {create.isPending ? 'Creating…' : 'Create'}
          </Button>
        </>
      }
    >
      <form onSubmit={submit} className="space-y-4">
        <Field label="Name" error={errors.name?.message}>
          <Input placeholder="e.g. Design Team" {...register('name')} />
        </Field>
        <Field label="Description" error={errors.description?.message}>
          <Textarea rows={3} placeholder="What is this workspace for?" {...register('description')} />
        </Field>
        {create.isError && (
          <p className="text-sm text-danger">Could not create the workspace. Please try again.</p>
        )}
      </form>
    </Modal>
  );
}

export function WorkspacesView() {
  const [search, setSearch] = useState('');
  const [modalOpen, setModalOpen] = useState(false);
  const [toDelete, setToDelete] = useState<Workspace | null>(null);
  const [toEdit, setToEdit] = useState<Workspace | null>(null);
  const isManager = useIsManager();
  const { data, isLoading, isError } = useWorkspaces();
  const del = useDeleteWorkspace();

  const workspaces = (data?.data ?? []).filter((ws) =>
    ws.name.toLowerCase().includes(search.toLowerCase()),
  );

  const confirmDelete = async () => {
    if (!toDelete) return;
    await del.mutateAsync(toDelete.id);
    setToDelete(null);
  };

  return (
    <div className="mx-auto max-w-[1400px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold text-ink sm:text-4xl">Work Spaces</h1>
          <p className="mt-1 text-ink-soft">Organize projects and teams by workspace.</p>
        </div>
        <Button onClick={() => setModalOpen(true)}>
          <PlusIcon className="h-4 w-4" />
          New Workspace
        </Button>
      </div>

      {/* Toolbar */}
      <div className="mt-6 flex items-center gap-3">
        <div className="relative max-w-sm flex-1">
          <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search workspaces…"
            className="pl-9"
          />
        </div>
      </div>

      {/* Content */}
      <div className="mt-6">
        {isLoading && <p className="text-ink-soft">Loading workspaces…</p>}

        {isError && (
          <Card className="p-8 text-center">
            <p className="font-semibold text-danger">Couldn&apos;t load workspaces.</p>
            <p className="mt-1 text-sm text-ink-soft">
              Make sure the API is running and you&apos;re signed in.
            </p>
          </Card>
        )}

        {!isLoading && !isError && workspaces.length === 0 && (
          <Card className="p-10 text-center">
            <p className="text-lg font-bold text-ink">No workspaces yet</p>
            <p className="mt-1 text-ink-soft">Create your first workspace to get started.</p>
            <Button className="mt-4" onClick={() => setModalOpen(true)}>
              <PlusIcon className="h-4 w-4" />
              New Workspace
            </Button>
          </Card>
        )}

        {!isLoading && workspaces.length > 0 && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {workspaces.map((ws) => (
              <Link key={ws.id} href={`/sprintly/workspaces/${ws.id}`}>
                <Card className="group relative h-full p-5 transition-shadow hover:shadow-md">
                  {isManager && (
                    <div className="absolute right-3 top-3 flex items-center gap-1 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                      <button
                        type="button"
                        aria-label={`Edit ${ws.name}`}
                        onClick={(e) => {
                          e.preventDefault();
                          e.stopPropagation();
                          setToEdit(ws);
                        }}
                        className="rounded-md p-1.5 text-ink-faint transition-colors hover:bg-brand/10 hover:text-brand"
                      >
                        <PencilIcon className="h-4 w-4" />
                      </button>
                      <button
                        type="button"
                        aria-label={`Delete ${ws.name}`}
                        onClick={(e) => {
                          e.preventDefault();
                          e.stopPropagation();
                          setToDelete(ws);
                        }}
                        className="rounded-md p-1.5 text-ink-faint transition-colors hover:bg-danger/10 hover:text-danger"
                      >
                        <TrashIcon className="h-4 w-4" />
                      </button>
                    </div>
                  )}
                  <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-soft text-lg font-bold text-[#0f8f8b]">
                    {ws.name.charAt(0).toUpperCase()}
                  </span>
                  <h3 className="mt-4 text-lg font-bold text-ink">{ws.name}</h3>
                  <p className="mt-1 line-clamp-2 text-sm text-ink-soft">
                    {ws.description || 'No description'}
                  </p>
                </Card>
              </Link>
            ))}
          </div>
        )}
      </div>

      <CreateWorkspaceModal open={modalOpen} onClose={() => setModalOpen(false)} />

      {toEdit && (
        <EditWorkspaceModal workspace={toEdit} onClose={() => setToEdit(null)} />
      )}

      <ConfirmDialog
        open={toDelete !== null}
        onClose={() => setToDelete(null)}
        onConfirm={confirmDelete}
        title="Delete workspace"
        confirmLabel="Delete workspace"
        confirmationText={toDelete?.name}
        isPending={del.isPending}
        description={
          <>
            This will archive the workspace{' '}
            <span className="font-bold text-ink">{toDelete?.name}</span>. You can restore it later
            from the Archives page.
          </>
        }
      />
    </div>
  );
}
