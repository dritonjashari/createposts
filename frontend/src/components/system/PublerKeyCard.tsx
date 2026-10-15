import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { savePublerKey, type SystemContext } from '../../api/system';
import SecretInput from '../ui/SecretInput';

interface Props {
  data: SystemContext;
  onSaved: (message: string) => void;
  onError: (err: unknown) => void;
}

export default function PublerKeyCard({ data, onSaved, onError }: Props) {
  const queryClient = useQueryClient();
  const [publerKey, setPublerKey] = useState('');

  useEffect(() => {
    setPublerKey(data.publer_api_key);
  }, [data]);

  const publerMutation = useMutation({
    mutationFn: () => savePublerKey(publerKey),
    onSuccess: (res) => {
      onSaved(res.message);
      queryClient.invalidateQueries({ queryKey: ['system'] });
      queryClient.invalidateQueries({ queryKey: ['workspaces'] }); // a new key can mean other workspaces
    },
    onError,
  });

  return (
    <div className="card">
      <h2>Publer</h2>
      <form
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          publerMutation.mutate();
        }}
      >
        <label>API key</label>
        <SecretInput value={publerKey} onChange={setPublerKey} placeholder="Leave empty to clear" />
        <button type="submit" disabled={publerMutation.isPending}>
          {publerMutation.isPending ? 'Saving…' : 'Save Publer key'}
        </button>
      </form>
    </div>
  );
}
