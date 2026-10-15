import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { saveActiveWorkspace, type SystemContext } from '../../api/system';

interface Props {
  data: SystemContext;
  onSaved: (message: string) => void;
  onError: (err: unknown) => void;
}

export default function WorkspaceCard({ data, onSaved, onError }: Props) {
  const queryClient = useQueryClient();
  const [activeWorkspace, setActiveWorkspace] = useState('');

  useEffect(() => {
    setActiveWorkspace(data.active_workspace_id);
  }, [data]);

  const workspaceMutation = useMutation({
    mutationFn: () => saveActiveWorkspace(activeWorkspace),
    onSuccess: (res) => {
      onSaved(res.message);
      // The sidebar switcher refetches everything else once it sees the new
      // active workspace (this page's data included).
      queryClient.invalidateQueries({ queryKey: ['workspaces'] });
    },
    onError,
  });

  return (
    <div className="card">
      <h2>Active Workspace</h2>
      <form
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          workspaceMutation.mutate();
        }}
      >
        <select value={activeWorkspace} onChange={(e) => setActiveWorkspace(e.target.value)}>
          {data.workspaces.map((ws) => (
            <option key={ws.id} value={ws.id}>
              {ws.name}
            </option>
          ))}
        </select>
        <button type="submit" disabled={workspaceMutation.isPending}>
          {workspaceMutation.isPending ? 'Saving…' : 'Save'}
        </button>
      </form>
    </div>
  );
}
