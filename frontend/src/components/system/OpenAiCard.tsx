import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { saveOpenAi, type SystemContext } from '../../api/system';
import SecretInput from '../ui/SecretInput';

interface Props {
  data: SystemContext;
  onSaved: (message: string) => void;
  onError: (err: unknown) => void;
}

export default function OpenAiCard({ data, onSaved, onError }: Props) {
  const queryClient = useQueryClient();
  const [openaiKey, setOpenaiKey] = useState('');
  const [openaiModel, setOpenaiModel] = useState('gpt-4o-mini');
  const [priceIn, setPriceIn] = useState('');
  const [priceOut, setPriceOut] = useState('');

  useEffect(() => {
    setOpenaiKey(data.openai.api_key);
    setOpenaiModel(data.openai.model);
    setPriceIn(data.openai.price_in);
    setPriceOut(data.openai.price_out);
  }, [data]);

  const openaiMutation = useMutation({
    mutationFn: () =>
      saveOpenAi({
        api_key: openaiKey,
        model: openaiModel,
        price_in: priceIn === '' ? undefined : Number(priceIn),
        price_out: priceOut === '' ? undefined : Number(priceOut),
      }),
    onSuccess: (res) => {
      onSaved(res.message);
      queryClient.invalidateQueries({ queryKey: ['system'] });
    },
    onError,
  });

  return (
    <div className="card">
      <h2>OpenAI</h2>
      <form
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          openaiMutation.mutate();
        }}
      >
        <label>API key</label>
        <SecretInput value={openaiKey} onChange={setOpenaiKey} placeholder="sk-…" />
        <label>Model</label>
        <input value={openaiModel} onChange={(e) => setOpenaiModel(e.target.value)} list="openai-models" />
        <datalist id="openai-models">
          <option value="gpt-4o-mini" />
          <option value="gpt-4o" />
          <option value="gpt-4.1-mini" />
          <option value="gpt-4.1" />
        </datalist>
        <label>Price per 1M input tokens (USD, optional override)</label>
        <input value={priceIn} onChange={(e) => setPriceIn(e.target.value)} placeholder={String(data.openai.effective_price_in)} />
        <label>Price per 1M output tokens (USD, optional override)</label>
        <input value={priceOut} onChange={(e) => setPriceOut(e.target.value)} placeholder={String(data.openai.effective_price_out)} />
        <p className="field-hint">
          Effective pricing: ${data.openai.effective_price_in}/1M in · ${data.openai.effective_price_out}/1M out. Spend to date: $
          {data.openai.spend.toFixed(4)}
        </p>
        <button type="submit" disabled={openaiMutation.isPending}>
          {openaiMutation.isPending ? 'Saving…' : 'Save OpenAI settings'}
        </button>
      </form>
    </div>
  );
}
