import React, { useEffect, useRef, useState } from 'react';
import { useHistory, useLocation, useParams } from 'react-router';
import { dirname } from 'path';

import { encodePathSegments, hashToPath } from '@/helpers';
import { httpErrorToHuman } from '@/api/http';
import getFileContents from '@/api/server/files/getFileContents';
import saveFileContents from '@/api/server/files/saveFileContents';
import FileNameModal from '@/components/server/files/FileNameModal';
import { ServerContext } from '@/state/server';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import useFlash from '@/plugins/useFlash';
import Can from '@/components/elements/Can';
import Select from '@/components/elements/Select';
import Button from '@/components/elements/Button';
import modes from '@/modes';
declare global {
    interface Window {
        monaco: any;
        require: any;
    }
}

// Add CSS to document head for responsive button styling
const addButtonStyles = () => {
    const styleId = 'responsive-button-styles';
    if (!document.getElementById(styleId)) {
        const style = document.createElement('style');
        style.id = styleId;
        style.textContent = `
            .responsive-button {
                flex: 1 1 0%;
            }
            @media (min-width: 640px) {
                .responsive-button {
                    flex: none;
                }
            }
        `;
        document.head.appendChild(style);
    }
};

const Editor = () => {
    // Router hooks
    const { hash } = useLocation();
    const history = useHistory();
    const { action } = useParams<{ action: 'new' | string }>();
    
    // Component state
    const [content, setContent] = useState('');
    const [modalVisible, setModalVisible] = useState(false);
    const [loading, setLoading] = useState(action === 'edit');
    const [monacoLoaded, setMonacoLoaded] = useState(false);
    const [lang, setLang] = useState('text/plain');
    
    // Refs
    const containerRef = useRef<HTMLDivElement>(null);
    const editorRef = useRef<any>(null);
    
    // Context and hooks
    const id = ServerContext.useStoreState((state) => state.server.data!.id);
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const setDirectory = ServerContext.useStoreActions((actions) => actions.files.setDirectory);
    const { addError, clearFlashes } = useFlash();
    
    // Auto-detect language based on file extension
    const detectLanguageFromPath = (filePath: string): string => {
        const extension = filePath.split('.').pop()?.toLowerCase();
        
        const extensionToMime: { [key: string]: string } = {
            // JavaScript/TypeScript
            'js': 'text/javascript',
            'jsx': 'text/javascript',
            'ts': 'application/typescript',
            'tsx': 'application/typescript',
            
            // Web technologies
            'html': 'text/html',
            'htm': 'text/html',
            'css': 'text/css',
            'scss': 'text/x-scss',
            'sass': 'text/x-sass',
            'xml': 'application/xml',
            
            // Data formats
            'json': 'application/json',
            'yaml': 'text/x-yaml',
            'yml': 'text/x-yaml',
            'toml': 'text/x-toml',
            
            // Programming languages
            'py': 'text/x-python',
            'php': 'text/x-php',
            'java': 'text/x-java',
            'c': 'text/x-csrc',
            'cpp': 'text/x-c++src',
            'cxx': 'text/x-c++src',
            'cc': 'text/x-c++src',
            'cs': 'text/x-csharp',
            'go': 'text/x-go',
            'rs': 'text/x-rustsrc',
            'rb': 'text/x-ruby',
            'lua': 'text/x-lua',
            
            // Shell/Config
            'sh': 'text/x-sh',
            'bash': 'text/x-sh',
            'zsh': 'text/x-sh',
            'dockerfile': 'text/x-dockerfile',
            'env': 'text/x-properties',
            'properties': 'text/x-properties',
            'conf': 'text/x-nginx-conf',
            'nginx': 'text/x-nginx-conf',
            
            // Database
            'sql': 'text/x-sql',
            
            // Documentation
            'md': 'text/x-markdown',
            'markdown': 'text/x-markdown',
            'txt': 'text/plain',
            
            // Other
            'diff': 'text/x-diff',
            'patch': 'text/x-diff',
            'vue': 'script/x-vue'
        };
        
        return extensionToMime[extension || ''] || 'text/plain';
    };
    
    // Map MIME types to Monaco language identifiers
    const getMonacoLanguage = (mimeType: string): string => {
        const languageMap: { [key: string]: string } = {
            'text/plain': 'plaintext',
            'application/json': 'json',
            'text/javascript': 'javascript',
            'application/javascript': 'javascript',
            'application/typescript': 'typescript',
            'text/typescript': 'typescript',
            'text/html': 'html',
            'text/css': 'css',
            'text/xml': 'xml',
            'application/xml': 'xml',
            'text/yaml': 'yaml',
            'application/x-yaml': 'yaml',
            'text/x-yaml': 'yaml',
            'application/yaml': 'yaml',
            'text/x-python': 'python',
            'application/x-python': 'python',
            'text/python': 'python',
            'application/x-php': 'php',
            'text/x-php': 'php',
            'text/php': 'php',
            'text/x-java': 'java',
            'application/java': 'java',
            'text/x-csharp': 'csharp',
            'text/csharp': 'csharp',
            'text/x-sql': 'sql',
            'application/sql': 'sql',
            'text/x-sh': 'shell',
            'application/x-sh': 'shell',
            'text/x-shellscript': 'shell',
            'application/x-shellscript': 'shell',
            'text/x-dockerfile': 'dockerfile',
            'application/x-dockerfile': 'dockerfile',
            'text/markdown': 'markdown',
            'text/x-markdown': 'markdown',
            'text/x-gfm': 'markdown',
            'application/x-httpd-php': 'php',
            'text/x-c': 'c',
            'text/x-csrc': 'c',
            'text/x-c++': 'cpp',
            'text/x-c++src': 'cpp',
            'text/x-cpp': 'cpp',
            'text/x-go': 'go',
            'text/x-ruby': 'ruby',
            'text/x-rustsrc': 'rust',
            'text/x-lua': 'lua',
            'text/x-sass': 'scss',
            'text/x-scss': 'scss',
            'text/x-toml': 'ini',
            'text/x-nginx-conf': 'nginx',
            'text/x-properties': 'ini',
            'text/x-diff': 'diff',
            'text/x-cassandra': 'sql',
            'text/x-mariadb': 'mysql',
            'text/x-mssql': 'sql',
            'text/x-mysql': 'mysql',
            'text/x-pgsql': 'pgsql',
            'text/x-sqlite': 'sql',
            'message/http': 'http',
            'script/x-vue': 'html'
        };
        return languageMap[mimeType] || 'plaintext';
    };

    // Auto-detect and set language when editing existing files
    useEffect(() => {
        if (action === 'edit' && hash) {
            const path = hashToPath(hash);
            const detectedMime = detectLanguageFromPath(path);
            setLang(detectedMime);
        }
    }, [action, hash]);
    
    const save = (name?: string) => {
        if (!editorRef.current) {
            return;
        }
        
        setLoading(true);
        clearFlashes('files:view');
        
        const editorContent = editorRef.current.getValue();
        const filePath = name || hashToPath(hash);
        
        saveFileContents(uuid, filePath, editorContent)
            .then(() => {
                // Update the content state to match what was saved
                setContent(editorContent);
                
                if (name) {
                    // For new files, navigate to edit mode with the new file
                    history.push(`/server/${id}/files/edit#/${encodePathSegments(name)}`);
                    // Update the directory context
                    setDirectory(dirname(name));
                }
            })
            .catch((error) => {
                console.error('Error saving file:', error);
                addError({ message: httpErrorToHuman(error), key: 'files:view' });
            })
            .then(() => setLoading(false));
    };
    // Load file contents for existing files
    useEffect(() => {
        if (action === 'new') return;
        
        setLoading(true);
        const path = hashToPath(hash);
        setDirectory(dirname(path));
        
        getFileContents(uuid, path)
            .then(setContent)
            .catch((error) => {
                console.error(error);
                addError({ message: httpErrorToHuman(error), key: 'files:view' });
            })
            .then(() => setLoading(false));
    }, [action, uuid, hash]);
    // Load Monaco Editor from CDN
    useEffect(() => {
        const loadMonaco = () => {
            // Check if Monaco is already loaded
            if (window.monaco) {
                setMonacoLoaded(true);
                return;
            }
            
            // Load the Monaco Editor CSS
            const cssLink = document.createElement('link');
            cssLink.rel = 'stylesheet';
            cssLink.href = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/editor/editor.main.css';
            document.head.appendChild(cssLink);
            
            // Load the Monaco Editor JavaScript
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/loader.js';
            script.onload = () => {
                window.require.config({
                    paths: {
                        vs: 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs'
                    }
                });
                window.require(['vs/editor/editor.main'], () => {
                    setMonacoLoaded(true);
                });
            };
            document.head.appendChild(script);
        };
        
        loadMonaco();
        
        // Cleanup function
        return () => {
            if (editorRef.current) {
                editorRef.current.dispose();
            }
        };
    }, []);
    // Initialize editor when both Monaco is loaded and we have content (or for new files)
    useEffect(() => {
        if (!monacoLoaded || !containerRef.current) {
            return;
        }
        
        // For new files, initialize immediately
        // For edit files, wait until content is loaded (loading is false)
        // But don't recreate editor when loading changes during save operations
        if ((action === 'new' && !editorRef.current) || (action === 'edit' && !loading && !editorRef.current)) { 
            // Destroy existing editor if it exists (should not happen with new logic)
            if (editorRef.current) {
                editorRef.current.dispose();
            }
            
            // For new files, use empty string if content is empty
            const initialContent = action === 'new' && !content ? '' : content;
            
            // Always start with plaintext for new files, language will be set separately
            const editorLanguage = 'plaintext';
            
            // Create the editor
            editorRef.current = window.monaco.editor.create(containerRef.current, {
                value: initialContent,
                language: editorLanguage,
                theme: 'vs-dark',
                automaticLayout: true,
                minimap: {
                    enabled: true
                },
                fontSize: 14,
                wordWrap: 'off',
                scrollBeyondLastLine: false
            });
            
            // Apply the current language after editor is fully initialized
            setTimeout(() => {
                if (editorRef.current && lang !== 'text/plain') {
                    const monacoLanguage = getMonacoLanguage(lang);
                    const model = editorRef.current.getModel();
                    if (model && window.monaco) {
                        window.monaco.editor.setModelLanguage(model, monacoLanguage);
                    }
                }
            }, 100);
        }
    }, [monacoLoaded, content, loading, action, lang]);
    // Update editor language when lang changes
    useEffect(() => {
        if (editorRef.current && window.monaco) {
            const model = editorRef.current.getModel();
            if (model) {
                const monacoLanguage = getMonacoLanguage(lang);
                
                // Check if the language is supported by Monaco
                const supportedLanguages = window.monaco.languages.getLanguages();
                const isSupported = supportedLanguages.some((l: any) => l.id === monacoLanguage);
                
                if (isSupported) {
                    window.monaco.editor.setModelLanguage(model, monacoLanguage);
                } else {
                    window.monaco.editor.setModelLanguage(model, 'plaintext');
                }
            }
        }
    }, [lang]);
    
    // Add keyboard shortcut for Ctrl+S (Windows/Linux) and Cmd+S (macOS)
    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                if (action === 'edit') {
                    save();
                } else if (action === 'new') {
                    setModalVisible(true);
                }
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => {
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [action, save]);

    // Update editor content when content state changes (for existing editor)
    useEffect(() => {
        if (editorRef.current && window.monaco && content !== editorRef.current.getValue()) {
            editorRef.current.setValue(content);
        }
    }, [content]);
    

    return (
        <>
            <FileNameModal 
                visible={modalVisible} 
                onDismissed={() => setModalVisible(false)} 
                onFileNamed={(name) => {
                    setModalVisible(false);
                    // Auto-detect language based on the new file name
                    const detectedMime = detectLanguageFromPath(name);
                    setLang(detectedMime);
                    save(name);
                }} 
            />
            
            <div style={{ position: 'relative' }}>
                <SpinnerOverlay visible={loading} />
                <div ref={containerRef} id="monaco-container" style={{borderRadius: 'var(--borderRadius)', overflow: 'hidden'}} />
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: '1rem' }}>
                <div className='FileEditContainer___StyledDiv5-sc-48rzpu-9 arKOj'>
                    <Select value={lang} onChange={(e) => setLang(e.currentTarget.value)}>
                        {modes.map((mode) => (
                            <option key={`${mode.name}_${mode.mime}`} value={mode.mime}>
                                {mode.name}
                            </option>
                        ))}
                    </Select>
                </div>
                
                {action === 'edit' ? (
                    <Can action={'file.update'}>
                        <Button onClick={() => save()}>
                            Save Content
                        </Button>
                    </Can>
                ) : (
                    <Can action={'file.create'}>
                        <Button onClick={() => setModalVisible(true)}>
                            Create File
                        </Button>
                    </Can>
                )}
            </div>
        </>
    );
};
export default Editor;