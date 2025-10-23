-- Create indexes on TempSplitTokens for fast lookups
CREATE INDEX idx_temp_split_eid ON TempSplitTokens(Eid);
CREATE INDEX idx_temp_split_valuetext ON TempSplitTokens(ValueText);
CREATE INDEX idx_temp_split_wordcount ON TempSplitTokens(WordCount);

