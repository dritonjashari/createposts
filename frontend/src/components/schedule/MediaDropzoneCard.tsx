import { useRef, useState, type ChangeEvent, type DragEvent } from 'react';
import Icon from '../ui/Icon';
import Toggle from '../ui/Toggle';

interface Props {
  media: File | null;
  mediaPreview: string | null;
  onFileChange: (file: File | null) => void;
  addPixels: boolean;
  onAddPixelsChange: (on: boolean) => void;
}

export default function MediaDropzoneCard({ media, mediaPreview, onFileChange, addPixels, onAddPixelsChange }: Props) {
  const [dragOver, setDragOver] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const isVideo = media?.type.startsWith('video') ?? false;

  return (
    <div className="card">
      <div className="publish-card-header" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12 }}>
        <div>
          <h2>Media</h2>
          <p className="field-note">Optional — one photo or video for the post.</p>
        </div>
        {media && (
          <button
            type="button"
            className="btn-small btn-secondary"
            onClick={() => {
              onFileChange(null);
              if (fileInputRef.current) fileInputRef.current.value = '';
            }}
          >
            <Icon name="trash" size={14} /> Remove
          </button>
        )}
      </div>
      <div
        className={`dropzone${dragOver ? ' dragover' : ''}`}
        role="button"
        tabIndex={0}
        onClick={() => fileInputRef.current?.click()}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            fileInputRef.current?.click();
          }
        }}
        onDragOver={(e: DragEvent) => {
          e.preventDefault();
          setDragOver(true);
        }}
        onDragLeave={() => setDragOver(false)}
        onDrop={(e: DragEvent) => {
          e.preventDefault();
          setDragOver(false);
          onFileChange(e.dataTransfer.files[0] ?? null);
        }}
      >
        {mediaPreview ? (
          <>
            {isVideo ? (
              <video className="dropzone-preview" src={mediaPreview} controls preload="metadata" onClick={(e) => e.stopPropagation()} />
            ) : (
              <img className="dropzone-preview" src={mediaPreview} alt="Preview" />
            )}
            {media && <span className="dropzone-filename">{media.name} · click to replace</span>}
          </>
        ) : (
          <>
            <span className="dropzone-icon">
              <Icon name="image" />
            </span>
            <span>
              <strong>Drop an image or video here</strong>
              <br />
              <span className="small-text">or click to choose a file · JPG, PNG, GIF, WebP, MP4, MOV</span>
            </span>
          </>
        )}
        <input
          ref={fileInputRef}
          type="file"
          accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,video/webm"
          onChange={(e: ChangeEvent<HTMLInputElement>) => onFileChange(e.target.files?.[0] ?? null)}
        />
      </div>
      <div className="media-options">
        <Toggle
          checked={addPixels && !isVideo}
          onChange={onAddPixelsChange}
          disabled={isVideo}
          label="Add random white pixels to the image"
          hint={
            isVideo
              ? 'Only applies to photos — a video is shared as-is.'
              : addPixels
                ? 'Every Facebook page gets its own slightly different copy of the photo.'
                : 'Off: every page gets the identical photo (uploaded once — faster).'
          }
        />
      </div>
    </div>
  );
}
