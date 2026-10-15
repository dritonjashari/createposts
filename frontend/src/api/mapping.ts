import { apiRequest } from './client';
import type { FbPage, Site, Workspace } from './types';

/** The workspaces this user may switch between, and the active one. */
export interface WorkspaceState {
  workspaces: Workspace[];
  active_workspace_id: string;
}

export function getWorkspaces() {
  return apiRequest<WorkspaceState>('workspace');
}

export function switchWorkspace(workspaceId: string) {
  return apiRequest<WorkspaceState>('workspace', {
    method: 'POST',
    body: { workspace_id: workspaceId },
  });
}

export interface MappingContext {
  workspaces: Workspace[];
  active_workspace_id: string;
  active_workspace_name: string;
  facebook_pages: FbPage[];
  sites: Site[];
  mappings: Record<string, number>;
}

export function getMapping() {
  return apiRequest<MappingContext>('mapping');
}

export function saveMapping(mapping: Record<string, number>) {
  return apiRequest<{ message: string; mappings: Record<string, number> }>('mapping', {
    method: 'POST',
    body: { mapping },
  });
}
